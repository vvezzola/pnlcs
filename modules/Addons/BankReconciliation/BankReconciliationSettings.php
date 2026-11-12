<?php

namespace Modules\Addons\BankReconciliation;

use App\Models\AddonSetting;

/**
 * The bank connection and the matching rule, as entered on the addon page
 * (addon = "bank_reconciliation"). Values are encrypted at rest by
 * AddonSetting.
 */
final class BankReconciliationSettings
{
    public const ADDON = 'bank_reconciliation';

    /**
     * Config fields rendered and saved by the addon skeleton.
     *
     * @return list<array{name: string, label: string, type: string, default?: string, hint?: string}>
     */
    public static function fields(): array
    {
        $t = fn (string $key): string => __('messages.bank_reconciliation.'.$key);

        return [
            ['name' => 'qonto_login', 'label' => $t('qonto_login'), 'type' => 'text', 'hint' => $t('qonto_login_hint')],
            ['name' => 'qonto_secret_key', 'label' => $t('qonto_secret_key'), 'type' => 'password'],
            ['name' => 'iban', 'label' => 'IBAN', 'type' => 'text', 'hint' => $t('iban_hint')],
            ['name' => 'sandbox', 'label' => $t('sandbox'), 'type' => 'yesno', 'default' => '0'],
            ['name' => 'staging_token', 'label' => $t('staging_token'), 'type' => 'password', 'hint' => $t('staging_token_hint')],
            ['name' => 'auto_apply', 'label' => $t('auto_apply'), 'type' => 'yesno', 'default' => '0', 'hint' => $t('auto_apply_hint')],
            ['name' => 'lookback_days', 'label' => $t('lookback_days'), 'type' => 'text', 'default' => '30', 'hint' => $t('lookback_days_hint')],
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

    public static function configured(): bool
    {
        $settings = self::resolve();

        return $settings['qonto_login'] !== '' && $settings['qonto_secret_key'] !== '' && $settings['iban'] !== ''
            && ($settings['sandbox'] !== '1' || $settings['staging_token'] !== '');
    }
}
