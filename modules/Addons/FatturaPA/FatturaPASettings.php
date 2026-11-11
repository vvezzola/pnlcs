<?php

namespace Modules\Addons\FatturaPA;

use App\Models\AddonSetting;

/**
 * The seller details and defaults an Italian e-invoice (FatturaPA) carries,
 * as the operator entered them on the addon page (addon = "fatturapa").
 */
final class FatturaPASettings
{
    /**
     * The client custom field that holds the buyer's SDI code or PEC address.
     * Its name is Italian on purpose: the field is filled in by Italian clients.
     */
    public const DEFAULT_RECIPIENT_FIELD = 'Codice destinatario SDI / PEC';

    /**
     * Config fields rendered and saved by the addon skeleton.
     *
     * @return array<int, array{name: string, label: string, type: string, default?: mixed, hint?: string, options?: array<string, string>}>
     */
    public static function fields(): array
    {
        $t = fn (string $key): string => __('messages.fatturapa.'.$key);

        return [
            ['name' => 'vat_number', 'label' => $t('vat_number'), 'type' => 'text', 'hint' => $t('vat_number_hint')],
            ['name' => 'fiscal_code', 'label' => $t('fiscal_code'), 'type' => 'text', 'hint' => $t('fiscal_code_hint')],
            ['name' => 'company_name', 'label' => $t('company_name'), 'type' => 'text', 'hint' => $t('company_name_hint')],
            ['name' => 'first_name', 'label' => $t('first_name'), 'type' => 'text'],
            ['name' => 'last_name', 'label' => $t('last_name'), 'type' => 'text'],
            ['name' => 'tax_regime', 'label' => $t('tax_regime'), 'type' => 'select', 'default' => 'RF01', 'options' => [
                'RF01' => 'RF01 '.$t('regime_ordinary'), 'RF02' => 'RF02 '.$t('regime_minimum'), 'RF19' => 'RF19 '.$t('regime_flat'),
            ]],
            ['name' => 'address', 'label' => $t('address'), 'type' => 'text'],
            ['name' => 'postcode', 'label' => $t('postcode'), 'type' => 'text'],
            ['name' => 'city', 'label' => $t('city'), 'type' => 'text'],
            ['name' => 'province', 'label' => $t('province'), 'type' => 'text', 'hint' => $t('province_hint')],
            ['name' => 'phone', 'label' => $t('phone'), 'type' => 'text'],
            ['name' => 'email', 'label' => 'Email', 'type' => 'text'],
            ['name' => 'transmitter_id', 'label' => $t('transmitter_id'), 'type' => 'text', 'hint' => $t('transmitter_id_hint')],
            ['name' => 'recipient_field', 'label' => $t('recipient_field'), 'type' => 'text', 'default' => self::DEFAULT_RECIPIENT_FIELD],
            ['name' => 'zero_rate_nature', 'label' => $t('zero_rate_nature'), 'type' => 'text', 'default' => 'N2.2', 'hint' => $t('zero_rate_nature_hint')],
            // The default reference is written into the e-invoice, which is read in Italian.
            ['name' => 'zero_rate_reference', 'label' => $t('zero_rate_reference'), 'type' => 'text', 'default' => 'Operazione non soggetta'],
            ['name' => 'vat_due', 'label' => $t('vat_due'), 'type' => 'select', 'default' => 'I', 'options' => [
                'I' => 'I '.$t('vat_immediate'), 'D' => 'D '.$t('vat_deferred'), 'S' => 'S '.$t('vat_split'),
            ]],
            ['name' => 'payment_method', 'label' => $t('payment_method'), 'type' => 'select', 'default' => 'MP05', 'options' => [
                'MP05' => 'MP05 '.$t('pay_transfer'), 'MP08' => 'MP08 '.$t('pay_card'), 'MP01' => 'MP01 '.$t('pay_cash'), 'MP02' => 'MP02 '.$t('pay_cheque'), 'MP12' => 'MP12 RIBA', 'MP19' => 'MP19 '.$t('pay_sepa'),
            ]],
            ['name' => 'iban', 'label' => 'IBAN', 'type' => 'text'],
            ['name' => 'bank_name', 'label' => $t('bank_name'), 'type' => 'text'],
            ['name' => 'beneficiary', 'label' => $t('beneficiary'), 'type' => 'text'],
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
