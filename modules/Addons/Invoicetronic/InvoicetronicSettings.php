<?php

namespace Modules\Addons\Invoicetronic;

use App\Models\AddonSetting;
use Illuminate\Support\Carbon;
use Modules\Addons\FatturaPA\FatturaPASettings;

/**
 * The connection to Invoicetronic and when invoices go out, as entered on the
 * addon page (addon = "invoicetronic"). The seller details the e-invoice
 * carries are the FatturaPA addon's. Values are encrypted at rest by
 * AddonSetting.
 */
final class InvoicetronicSettings
{
    public const ADDON = 'invoicetronic';

    /**
     * Config fields rendered and saved by the addon skeleton.
     *
     * @return list<array{name: string, label: string, type: string, default?: string, hint?: string}>
     */
    public static function fields(): array
    {
        $t = fn (string $key): string => __('messages.invoicetronic.'.$key);

        return [
            ['name' => 'api_key', 'label' => $t('api_key'), 'type' => 'password', 'hint' => $t('api_key_hint')],
            ['name' => 'auto_send', 'label' => $t('auto_send'), 'type' => 'yesno', 'default' => '0', 'hint' => $t('auto_send_hint')],
            ['name' => 'send_from', 'label' => $t('send_from'), 'type' => 'text', 'hint' => $t('send_from_hint')],
            ['name' => 'delay_minutes', 'label' => $t('delay_minutes'), 'type' => 'text', 'default' => '60', 'hint' => $t('delay_minutes_hint')],
        ];
    }

    /** @return array<string, string> setting => value, defaults filled in */
    public static function resolve(): array
    {
        $stored = AddonSetting::getForAddon(self::ADDON);
        $settings = [];

        foreach (self::fields() as $field) {
            $value = trim((string) ($stored[$field['name']] ?? ''));
            $settings[$field['name']] = $value !== '' ? $value : (string) ($field['default'] ?? '');
        }

        return $settings;
    }

    /** An API key, and the seller's VAT number on the FatturaPA addon. */
    public static function configured(): bool
    {
        return self::resolve()['api_key'] !== '' && FatturaPASettings::resolve()['vat_number'] !== '';
    }

    /** A test key reaches Invoicetronic's sandbox, which only imitates the SDI. */
    public static function isSandbox(): bool
    {
        return str_starts_with(self::resolve()['api_key'], 'ik_test_');
    }

    /**
     * The first invoice date sent on its own. Without one nothing is sent on
     * its own, so turning the addon on never sends invoices issued before it.
     */
    public static function sendFrom(): ?Carbon
    {
        $value = self::resolve()['send_from'];

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    /** Minutes an invoice waits after it is created, so staff can still correct it. */
    public static function delayMinutes(): int
    {
        return max(0, (int) self::resolve()['delay_minutes']);
    }
}
