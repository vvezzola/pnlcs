<?php

namespace Modules\Addons\Pec;

use App\Models\AddonSetting;

/** The sending mailbox and its servers (addon = "pec"). */
final class PecSettings
{
    /** @return array<int, array<string, mixed>> */
    public static function fields(): array
    {
        return [
            ['name' => 'address', 'label' => __('messages.pec.address'), 'type' => 'text', 'hint' => __('messages.pec.address_hint')],
            ['name' => 'password', 'label' => __('messages.pec.password'), 'type' => 'password'],
            ['name' => 'smtp_host', 'label' => __('messages.pec.smtp_host'), 'type' => 'text', 'default' => 'smtps.pec.aruba.it'],
            ['name' => 'smtp_port', 'label' => __('messages.pec.smtp_port'), 'type' => 'text', 'default' => '465'],
            ['name' => 'imap_host', 'label' => __('messages.pec.imap_host'), 'type' => 'text', 'default' => 'imaps.pec.aruba.it'],
            ['name' => 'imap_port', 'label' => __('messages.pec.imap_port'), 'type' => 'text', 'default' => '993'],
            ['name' => 'client_field', 'label' => __('messages.pec.client_field'), 'type' => 'text', 'hint' => __('messages.pec.client_field_hint')],
        ];
    }

    /** @return array<string, string> */
    public static function resolve(): array
    {
        $stored = AddonSetting::getForAddon('pec');
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

        return filter_var($settings['address'], FILTER_VALIDATE_EMAIL) !== false && $settings['password'] !== '';
    }
}
