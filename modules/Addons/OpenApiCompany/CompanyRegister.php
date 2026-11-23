<?php

namespace Modules\Addons\OpenApiCompany;

use Illuminate\Support\Facades\Cache;

/**
 * Looks an Italian company up in the business register by VAT number.
 *
 * Only a VAT number that passes its check digit is sent, and an answer,
 * "unknown" included, is kept for the addon's cache days, so the same
 * number is never paid for twice in that time.
 */
class CompanyRegister
{
    public function __construct(private ?OpenApiCompanyClient $client = null) {}

    /**
     * @param  string  $purpose  why it was asked (registration, check, admin), recorded with the call
     *
     * @throws OpenApiCompanyUnavailable
     */
    public function find(string $vatCode, string $purpose): ?ItalianCompany
    {
        $vatCode = self::normalise($vatCode);
        if (! self::isValidVat($vatCode)) {
            return null;
        }

        $key = 'openapi_company:'.$vatCode;
        $cached = Cache::get($key);
        if (is_array($cached)) {
            return $cached === [] ? null : ItalianCompany::fromArray($cached);
        }

        $client = $this->client ?? new OpenApiCompanyClient(OpenApiCompanySettings::resolve()['token'], OpenApiCompanySettings::baseUrl());
        $start = $client->start($vatCode, $purpose);
        $company = null;
        if ($start !== null) {
            $company = ItalianCompany::fromStart($start);
            $company->vatCode = $company->vatCode !== '' ? $company->vatCode : $vatCode;
            $company->pec = $company->isCeased() ? null : $client->pec($vatCode, $purpose);
        }

        Cache::put($key, $company?->toArray() ?? [], now()->addDays(OpenApiCompanySettings::cacheDays()));

        return $company;
    }

    /** Digits only, without the IT prefix. */
    public static function normalise(string $vatCode): string
    {
        $vatCode = strtoupper(preg_replace('/[\s.\-]/', '', $vatCode));

        return str_starts_with($vatCode, 'IT') ? substr($vatCode, 2) : $vatCode;
    }

    /** Eleven digits whose last one is the Italian check digit. */
    public static function isValidVat(string $vatCode): bool
    {
        if (! preg_match('/^\d{11}$/', $vatCode) || $vatCode === '00000000000') {
            return false;
        }

        $sum = 0;
        for ($i = 0; $i < 10; $i++) {
            $digit = (int) $vatCode[$i];
            if ($i % 2 === 1) {
                $digit *= 2;
                $digit = $digit > 9 ? $digit - 9 : $digit;
            }
            $sum += $digit;
        }

        return (10 - $sum % 10) % 10 === (int) $vatCode[10];
    }
}
