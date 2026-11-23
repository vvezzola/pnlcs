<?php

namespace Modules\Addons\OpenApiCompany;

use App\Models\AddonSetting;

/**
 * The connection to OpenAPI's Company service, as entered on the addon page
 * (addon = "openapi_company"). The token is encrypted at rest by AddonSetting.
 */
final class OpenApiCompanySettings
{
    public const ADDON = 'openapi_company';

    /** @return list<array{name: string, label: string, type: string, default?: string, hint?: string}> */
    public static function fields(): array
    {
        $t = fn (string $key): string => __('messages.openapi_company.'.$key);

        return [
            ['name' => 'token', 'label' => $t('token'), 'type' => 'password', 'hint' => $t('token_hint')],
            ['name' => 'sandbox', 'label' => $t('sandbox'), 'type' => 'yesno', 'default' => '0', 'hint' => $t('sandbox_hint')],
            ['name' => 'cache_days', 'label' => $t('cache_days'), 'type' => 'text', 'default' => '30', 'hint' => $t('cache_days_hint')],
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
        return self::resolve()['token'] !== '';
    }

    public static function baseUrl(): string
    {
        return self::resolve()['sandbox'] === '1' ? 'https://test.company.openapi.com' : 'https://company.openapi.com';
    }

    /** How long an answer is kept before the registry is asked again. */
    public static function cacheDays(): int
    {
        return max(1, min(365, (int) self::resolve()['cache_days']));
    }
}
