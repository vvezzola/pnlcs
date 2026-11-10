<?php

namespace Modules\CompanyLookup;

use Illuminate\Support\Facades\Http;
use Modules\CompanyLookup\Exceptions\ProviderException;

/**
 * The European Commission's VIES service: for a VAT number of any EU country
 * it answers whether the number is valid and, where the member state shares
 * them, the registered name and address. Free and keyless.
 *
 * VIES hands the address back as free text, one line per row. Italy writes
 * "VIA ROMA 1 \n24121 BERGAMO BG\n"; that shape is read into street, postal
 * code, city and province. Other countries keep the street line and, when
 * the last line starts with a postal code, the code and the city.
 */
final class ViesProvider
{
    private const ENDPOINT = 'https://ec.europa.eu/taxation_customs/vies/rest-api/ms/%s/vat/%s';

    /** EU member states VIES answers for (Greece is EL there). */
    public const COUNTRIES = ['AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'EL', 'ES', 'FI', 'FR', 'HR', 'HU', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK', 'XI'];

    public function __construct(private readonly int $timeout = 10) {}

    /**
     * @throws ProviderException when VIES cannot be reached or the member state's service is down
     */
    public function find(string $country, string $number): ?CompanyData
    {
        $country = $country === 'GR' ? 'EL' : strtoupper($country);

        try {
            $response = Http::timeout($this->timeout)->acceptJson()->get(sprintf(self::ENDPOINT, $country, rawurlencode($number)));
        } catch (\Throwable $e) {
            throw new ProviderException('VIES: '.$e->getMessage(), ProviderException::VIES_ERROR);
        }

        $data = $response->json();
        if (! $response->successful() || ! is_array($data)) {
            throw new ProviderException('VIES: HTTP '.$response->status(), ProviderException::VIES_ERROR);
        }

        // A member state's own register being down is not "no such company".
        if (in_array($data['userError'] ?? 'VALID', ['MS_UNAVAILABLE', 'TIMEOUT', 'SERVICE_UNAVAILABLE', 'MS_MAX_CONCURRENT_REQ', 'GLOBAL_MAX_CONCURRENT_REQ'], true)) {
            throw new ProviderException('VIES: '.$data['userError'], ProviderException::VIES_ERROR);
        }

        if (($data['isValid'] ?? false) !== true) {
            return null;
        }

        $name = $this->clean($data['name'] ?? null);
        $lines = array_values(array_filter(array_map(fn ($line) => trim($line), preg_split('/\r?\n/', (string) ($data['address'] ?? '')) ?: []), fn ($line) => $line !== '' && $line !== '---'));

        $company = new CompanyData(
            name: $name,
            nip: (string) ($data['vatNumber'] ?? $number),
            street: $lines[0] ?? null,
            country: $country === 'EL' ? 'GR' : $country,
            vatStatus: 'VALID',
        );

        $last = count($lines) > 1 ? $lines[count($lines) - 1] : null;
        if ($last !== null && $country === 'IT' && preg_match('/^(\d{5})\s+(.+?)(?:\s+([A-Z]{2}))?$/u', $last, $m)) {
            $company->postalCode = $m[1];
            $company->city = $m[2];
            $company->voivodeship = $m[3] ?? null;
        } elseif ($last !== null && preg_match('/^([A-Z]{0,2}-?\d[\d\s-]{2,8})\s+(.+)$/u', $last, $m)) {
            $company->postalCode = trim($m[1]);
            $company->city = $m[2];
        }

        return $company;
    }

    private function clean(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' || $value === '---' ? null : $value;
    }
}
