<?php

namespace Modules\Addons\SimplyFatt;

use App\Models\AddonSetting;

/** What the SF-XML file needs that PNLCS does not know by itself (addon = "simplyfatt"). */
final class SimplyFattSettings
{
    /**
     * @return array<int, array{name: string, label: string, type: string, default?: string, hint?: string}>
     */
    public static function fields(): array
    {
        return [
            ['name' => 'customer_code_field', 'label' => 'Campo cliente con il codice SimplyFatt', 'type' => 'text', 'default' => 'Codice SimplyFatt'],
            ['name' => 'recipient_field', 'label' => 'Campo cliente con codice SDI o PEC', 'type' => 'text', 'default' => 'Codice destinatario SDI / PEC'],
            ['name' => 'payment_name', 'label' => 'Nome del pagamento in SimplyFatt', 'type' => 'text', 'default' => 'Bonifico 30gg FM'],
            ['name' => 'bank_name', 'label' => 'Banca', 'type' => 'text'],
            ['name' => 'bank_coordinates', 'label' => 'Coordinate bancarie', 'type' => 'text', 'hint' => 'Es. IBAN: IT.. - BIC: ..'],
            ['name' => 'zero_vat_code', 'label' => 'Codice IVA SimplyFatt per le righe senza IVA', 'type' => 'text', 'default' => 'N2.2'],
            ['name' => 'foot_notes', 'label' => 'Note a piè di pagina', 'type' => 'textarea'],
        ];
    }

    /** @return array<string, string> */
    public static function resolve(): array
    {
        $stored = AddonSetting::getForAddon('simplyfatt');
        $settings = [];

        foreach (self::fields() as $field) {
            $value = trim((string) ($stored[$field['name']] ?? ''));
            $settings[$field['name']] = $value !== '' ? $value : (string) ($field['default'] ?? '');
        }

        return $settings;
    }
}
