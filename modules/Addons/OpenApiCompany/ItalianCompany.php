<?php

namespace Modules\Addons\OpenApiCompany;

/**
 * What the Italian business register says about a company, from OpenAPI's
 * IT-start and IT-pec answers. Only this shape leaves the addon.
 */
final class ItalianCompany
{
    public function __construct(
        public string $vatCode,
        public ?string $taxCode,
        public ?string $name,
        public ?string $address,
        public ?string $postcode,
        public ?string $city,
        public ?string $province,
        public ?string $activityStatus,
        public ?string $sdiCode,
        public ?string $pec = null,
    ) {}

    /** @param  array<string, mixed>  $row  one entry of IT-start's "data" */
    public static function fromStart(array $row): self
    {
        $office = (array) ($row['address']['registeredOffice'] ?? []);
        $text = fn (mixed $value): ?string => is_string($value) && trim($value) !== '' ? trim($value) : null;
        $sdi = strtoupper((string) $text($row['sdiCode'] ?? null));

        return new self(
            vatCode: (string) ($text($row['vatCode'] ?? null) ?? ''),
            taxCode: $text($row['taxCode'] ?? null),
            name: $text($row['companyName'] ?? null),
            address: $text($office['streetName'] ?? null),
            postcode: $text($office['zipCode'] ?? null),
            city: $text($office['town'] ?? null),
            province: $text($office['province'] ?? null),
            activityStatus: $text($row['activityStatus'] ?? null),
            sdiCode: preg_match('/^[A-Z0-9]{7}$/', $sdi) && $sdi !== '0000000' ? $sdi : null,
        );
    }

    /** The register lists the company as closed down. */
    public function isCeased(): bool
    {
        return $this->activityStatus !== null && str_starts_with(strtoupper($this->activityStatus), 'CESSAT');
    }

    /** @return array<string, string|null> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    /** @param  array<string, string|null>  $data */
    public static function fromArray(array $data): self
    {
        return new self(...$data);
    }
}
