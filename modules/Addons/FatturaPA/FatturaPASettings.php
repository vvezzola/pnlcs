<?php

namespace Modules\Addons\FatturaPA;

use App\Models\AddonSetting;

/**
 * The seller details and defaults an Italian e-invoice (FatturaPA) carries,
 * as the operator entered them on the addon page (addon = "fatturapa").
 */
final class FatturaPASettings
{
    /** The client custom field that holds the buyer's SDI code or PEC address. */
    public const DEFAULT_RECIPIENT_FIELD = 'Codice destinatario SDI / PEC';

    /**
     * Config fields rendered and saved by the addon skeleton.
     *
     * @return array<int, array{name: string, label: string, type: string, default?: mixed, hint?: string, options?: array<string, string>}>
     */
    public static function fields(): array
    {
        return [
            ['name' => 'vat_number', 'label' => 'Partita IVA', 'type' => 'text', 'hint' => '11 cifre, senza IT'],
            ['name' => 'fiscal_code', 'label' => 'Codice fiscale', 'type' => 'text', 'hint' => 'Per una ditta individuale, il codice fiscale del titolare'],
            ['name' => 'company_name', 'label' => 'Denominazione', 'type' => 'text', 'hint' => 'Per una società. Una ditta individuale lascia vuoto e compila nome e cognome'],
            ['name' => 'first_name', 'label' => 'Nome', 'type' => 'text'],
            ['name' => 'last_name', 'label' => 'Cognome', 'type' => 'text'],
            ['name' => 'tax_regime', 'label' => 'Regime fiscale', 'type' => 'select', 'default' => 'RF01', 'options' => [
                'RF01' => 'RF01 Ordinario', 'RF02' => 'RF02 Contribuenti minimi', 'RF19' => 'RF19 Forfettario',
            ]],
            ['name' => 'address', 'label' => 'Indirizzo', 'type' => 'text'],
            ['name' => 'postcode', 'label' => 'CAP', 'type' => 'text'],
            ['name' => 'city', 'label' => 'Comune', 'type' => 'text'],
            ['name' => 'province', 'label' => 'Provincia', 'type' => 'text', 'hint' => 'Sigla, es. BS'],
            ['name' => 'phone', 'label' => 'Telefono', 'type' => 'text'],
            ['name' => 'email', 'label' => 'Email', 'type' => 'text'],
            ['name' => 'transmitter_id', 'label' => 'Codice fiscale del trasmittente', 'type' => 'text', 'hint' => 'Vuoto = la tua partita IVA. Chi trasmette (Aruba, il commercialista) di solito lo sostituisce con il proprio'],
            ['name' => 'recipient_field', 'label' => 'Campo cliente con codice SDI o PEC', 'type' => 'text', 'default' => self::DEFAULT_RECIPIENT_FIELD],
            ['name' => 'zero_rate_nature', 'label' => 'Natura per le righe senza IVA', 'type' => 'text', 'default' => 'N2.2', 'hint' => 'Es. N2.2 non soggette, N4 esenti'],
            ['name' => 'zero_rate_reference', 'label' => 'Riferimento normativo per le righe senza IVA', 'type' => 'text', 'default' => 'Operazione non soggetta'],
            ['name' => 'vat_due', 'label' => 'Esigibilità IVA', 'type' => 'select', 'default' => 'I', 'options' => [
                'I' => 'I Immediata', 'D' => 'D Differita', 'S' => 'S Scissione dei pagamenti',
            ]],
            ['name' => 'payment_method', 'label' => 'Modalità di pagamento', 'type' => 'select', 'default' => 'MP05', 'options' => [
                'MP05' => 'MP05 Bonifico', 'MP08' => 'MP08 Carta di pagamento', 'MP01' => 'MP01 Contanti', 'MP02' => 'MP02 Assegno', 'MP12' => 'MP12 RIBA', 'MP19' => 'MP19 SEPA Direct Debit',
            ]],
            ['name' => 'iban', 'label' => 'IBAN', 'type' => 'text'],
            ['name' => 'bank_name', 'label' => 'Istituto finanziario', 'type' => 'text'],
            ['name' => 'beneficiary', 'label' => 'Beneficiario del pagamento', 'type' => 'text'],
        ];
    }

    /** @return array<string, string> setting => value, defaults filled in */
    public static function resolve(): array
    {
        $stored = AddonSetting::getForAddon('fatturapa');
        $settings = [];

        foreach (self::fields() as $field) {
            $value = trim((string) ($stored[$field['name']] ?? ''));
            $settings[$field['name']] = $value !== '' ? $value : (string) ($field['default'] ?? '');
        }

        return $settings;
    }
}
