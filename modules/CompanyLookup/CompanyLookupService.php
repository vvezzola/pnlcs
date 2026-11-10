<?php

namespace Modules\CompanyLookup;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Modules\CompanyLookup\Contracts\CompanyDataProviderInterface;
use Modules\CompanyLookup\Exceptions\ProviderException;
use Modules\CompanyLookup\Support\Nip;

/**
 * Orchestrates the four registries and hands back one normalised answer.
 *
 * Identification (name / NIP / REGON / address / legal form / PKD) prefers
 * GUS → CEIDG → MF → OpenBRIS; VAT status and bank accounts come from MF;
 * business status and activity dates come from CEIDG. Any source can fail
 * without sinking the whole lookup — a partial result ships with a warning,
 * and per-field discrepancies are surfaced instead of silently discarded.
 */
final class CompanyLookupService
{
    public function __construct(
        private readonly CompanyDataProviderInterface $gus,
        private readonly CompanyDataProviderInterface $mf,
        private readonly CompanyDataProviderInterface $ceidg,
        private readonly CompanyDataProviderInterface $openbris,
        private readonly DataNormalizer $normalizer,
        private readonly int $cacheTtl,
        private readonly ViesProvider $vies = new ViesProvider,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function lookup(string $rawNip, ?string $country = null): array
    {
        $startedAt = microtime(true);

        // A VAT number of another EU country - written with its prefix
        // (IT01234567890), or typed bare on a client whose country is not
        // Poland - goes to VIES; the Polish registries know only NIPs.
        $vat = $this->foreignVat($rawNip, $country);
        if ($vat !== null) {
            return $this->lookupVies($vat[0], $vat[1], $vat[2], $startedAt);
        }

        $nip = Nip::normalize($rawNip);
        if (! Nip::isValidDigits($nip)) {
            return $this->failed('INVALID_NIP', $nip, $startedAt);
        }

        $cacheKey = 'company_lookup:'.$nip;
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $sources = ['gus' => false, 'mf' => false, 'ceidg' => false, 'openbris' => false];
        $warnings = [];

        $gus = $this->call('gus', $this->gus, $nip, $sources, $warnings);
        $mf = $this->call('mf', $this->mf, $nip, $sources, $warnings);
        $ceidg = $this->call('ceidg', $this->ceidg, $nip, $sources, $warnings);
        $openbris = $this->call('openbris', $this->openbris, $nip, $sources, $warnings);

        $normalized = $this->normalizer->normalize($gus, $ceidg, $mf, $openbris);
        $company = $normalized['company'];

        // Discrepancy warnings are structured objects; append them to the
        // textual provider warnings.
        $warnings = array_merge($warnings, $normalized['warnings']);

        if (! $company->hasAnyData()) {
            // At least one source answered but knew nothing → genuinely absent.
            // No source answered at all → a failure, not a "not found".
            $code = ($sources['gus'] || $sources['mf'] || $sources['ceidg'] || $sources['openbris'])
                ? 'COMPANY_NOT_FOUND'
                : 'UNKNOWN_ERROR';

            return $this->failed($code, $nip, $startedAt, $sources, $warnings);
        }

        $result = [
            'success' => true,
            'company' => $company->toArray(),
            'sources' => $sources,
            'warnings' => $warnings,
        ];

        if ($this->cacheTtl > 0) {
            Cache::put($cacheKey, $result, $this->cacheTtl);
        }

        $this->logSuccess($nip, $sources, $startedAt);

        return $result;
    }

    /**
     * The country and number of a VAT number VIES should answer, or null for
     * a Polish NIP.
     *
     * @return array{0: string, 1: string, 2: string}|null
     */
    private function foreignVat(string $raw, ?string $country): ?array
    {
        $compact = strtoupper((string) preg_replace('/[\s.\-]/', '', $raw));

        if (preg_match('/^([A-Z]{2})([0-9A-Z]{2,13})$/', $compact, $m) && in_array($m[1], ViesProvider::COUNTRIES, true)) {
            return $m[1] === 'PL' ? null : [$m[1], $m[2], $compact];
        }

        $country = strtoupper((string) $country);
        if ($country === 'GR') {
            $country = 'EL';
        }
        if ($country !== '' && $country !== 'PL' && in_array($country, ViesProvider::COUNTRIES, true) && preg_match('/^[0-9A-Z]{2,13}$/', $compact)) {
            return [$country, $compact, $compact];
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function lookupVies(string $country, string $number, string $asTyped, float $startedAt): array
    {
        $cacheKey = 'company_lookup:vies:'.$country.$number;
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        try {
            $company = $this->vies->find($country, $number);
        } catch (ProviderException $e) {
            $this->logProviderFailure('vies', $e);

            return $this->failed('UNKNOWN_ERROR', $country.$number, $startedAt, warnings: [__('messages.company_lookup.vies_unavailable')]);
        }

        if ($company === null) {
            return $this->failed('COMPANY_NOT_FOUND', $country.$number, $startedAt);
        }
        // Back in the form the number keeps the shape the operator typed it in.
        $company->nip = $asTyped;

        $result = ['success' => true, 'company' => $company->toArray(), 'sources' => ['vies' => true], 'warnings' => []];
        if ($this->cacheTtl > 0) {
            Cache::put($cacheKey, $result, $this->cacheTtl);
        }

        return $result;
    }

    /**
     * Call one provider, converting failures into per-source flags/warnings.
     *
     * NOT_CONFIGURED is skipped silently (an optional source with no key yet);
     * any other failure produces a textual warning. Returns the CompanyData or
     * null when the source found nothing.
     *
     * @param  array{gus: bool, mf: bool, ceidg: bool, openbris: bool}  $sources
     * @param  array<int, string|array<string, mixed>>  $warnings
     */
    private function call(
        string $name,
        CompanyDataProviderInterface $provider,
        string $nip,
        array &$sources,
        array &$warnings,
    ): ?CompanyData {
        try {
            $data = $provider->findByNip($nip);
            $sources[$name] = true;

            return $data;
        } catch (ProviderException $e) {
            $sources[$name] = false;

            if ($e->codeName() === ProviderException::NOT_CONFIGURED) {
                return null;
            }

            $warnings[] = __('messages.company_lookup.'.$name.'_unavailable');
            $this->logProviderFailure($name, $e);

            return null;
        }
    }

    /**
     * @param  array{gus: bool, mf: bool, ceidg: bool, openbris: bool}  $sources
     * @param  array<int, string|array<string, mixed>>  $warnings
     * @return array<string, mixed>
     */
    private function failed(
        string $code,
        string $nip,
        float $startedAt,
        array $sources = ['gus' => false, 'mf' => false, 'ceidg' => false, 'openbris' => false],
        array $warnings = [],
    ): array {
        Log::channel('daily')->warning('Company lookup failed', [
            'nip' => $nip,
            'code' => $code,
            'sources' => $sources,
            'duration_ms' => $this->elapsed($startedAt),
        ]);

        return [
            'success' => false,
            'error' => $code,
            'company' => null,
            'sources' => $sources,
            'warnings' => $warnings,
        ];
    }

    /**
     * @param  array{gus: bool, mf: bool, ceidg: bool, openbris: bool}  $sources
     */
    private function logSuccess(string $nip, array $sources, float $startedAt): void
    {
        Log::channel('daily')->info('Company lookup ok', [
            'nip' => $nip,
            'sources' => $sources,
            'duration_ms' => $this->elapsed($startedAt),
        ]);
    }

    private function logProviderFailure(string $source, ProviderException $e): void
    {
        Log::channel('daily')->warning("Company lookup provider [{$source}] failed", [
            'code' => $e->codeName(),
            'message' => $e->getMessage(),
        ]);
    }

    private function elapsed(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
