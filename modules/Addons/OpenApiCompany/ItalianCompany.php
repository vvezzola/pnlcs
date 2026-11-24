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
            address: self::ordinaryCase($text($office['streetName'] ?? null)),
            postcode: $text($office['zipCode'] ?? null),
            city: self::ordinaryCase($text($office['town'] ?? null)),
            province: $text($office['province'] ?? null),
            activityStatus: $text($row['activityStatus'] ?? null),
            sdiCode: preg_match('/^[A-Z0-9]{7}$/', $sdi) && $sdi !== '0000000' ? $sdi : null,
        );
    }

    /**
     * An address or a town as people write it, from the register's capitals:
     * "VIA DELL'ARTIGIANATO 2" becomes "Via dell'Artigianato 2", "PRATO ALLO
     * STELVIO" "Prato allo Stelvio"; Roman numerals stay in capitals ("Via
     * XX Settembre"). Text that is not all capitals is left as it is.
     */
    public static function ordinaryCase(?string $text): ?string
    {
        if ($text === null || $text !== mb_strtoupper($text)) {
            return $text;
        }

        $small = ['a', 'ad', 'al', 'alla', 'alle', 'allo', 'agli', 'ai', 'con', 'da', 'dal', 'dalla', 'dalle', 'dei', 'del', 'della', 'delle', 'dello',
            'degli', 'di', 'e', 'ed', 'in', 'nel', 'nella', 'per', 'sul', 'sulla', 'su', 'tra', 'fra', 'de\''];
        $words = preg_split('/(\s+)/u', mb_strtolower($text), -1, PREG_SPLIT_DELIM_CAPTURE);

        foreach ($words as $i => $word) {
            if (trim($word) === '') {
                continue;
            }
            if ($word !== 'di' && preg_match('/^(?=[mdclxvi])m*(c[md]|d?c{0,3})(x[cl]|l?x{0,3})(i[xv]|v?i{0,3})$/u', $word)) {
                $words[$i] = mb_strtoupper($word);

                continue;
            }
            // "dell'artigianato": the article stays small, the name after it does not.
            if (preg_match("/^(\p{L}+)'(\p{L}.*)$/u", $word, $m)) {
                $head = $i > 0 && in_array($m[1]."'", ['dell\'', 'dall\'', 'all\'', 'nell\'', 'sull\'', 'l\'', 'd\''], true) && $m[1] !== 'd' ? $m[1] : mb_convert_case($m[1], MB_CASE_TITLE);
                $words[$i] = $head."'".mb_convert_case($m[2], MB_CASE_TITLE);

                continue;
            }
            $words[$i] = $i > 0 && in_array($word, $small, true) ? $word : mb_convert_case($word, MB_CASE_TITLE);
        }

        return implode('', $words);
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
