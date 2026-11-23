<?php

namespace Modules\Addons\OpenApiCompany;

use App\Contracts\AddonModuleInterface;
use Illuminate\Http\Request;

/**
 * Italian company data from the business register through OpenAPI's Company
 * service: name, address, activity status, SDI code and PEC by VAT number.
 */
class OpenApiCompanyModule implements AddonModuleInterface
{
    public function getName(): string
    {
        return OpenApiCompanySettings::ADDON;
    }

    public function getDisplayName(): string
    {
        return __('messages.openapi_company.title');
    }

    public function getDescription(): string
    {
        return __('messages.openapi_company.description');
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getAuthor(): string
    {
        return 'PNLCS';
    }

    /** @return array<int, array{version:string, date:string, changes:list<string>}> */
    public function changelog(): array
    {
        return [['version' => '1.0.0', 'date' => '2026-11-23', 'changes' => ['Italian companies looked up by VAT number (IT-start and IT-pec); answers kept for the cache days; every paid call recorded.']]];
    }

    /** @return array{success: bool, message: string} */
    public function activate(): array
    {
        return ['success' => true, 'message' => __('messages.openapi_company.activated')];
    }

    /** @return array{success: bool, message: string} */
    public function deactivate(): array
    {
        return ['success' => true, 'message' => __('messages.openapi_company.deactivated')];
    }

    /** @return array<int, array<string, mixed>> */
    public function config(): array
    {
        return OpenApiCompanySettings::fields();
    }

    /** @return array<int, array<string, mixed>> */
    public function sidebar(): array
    {
        return [];
    }

    /** @return array{success: bool, message: string} */
    public function upgrade(string $fromVersion): array
    {
        return ['success' => true, 'message' => 'OpenAPI Company upgraded from '.e($fromVersion)];
    }

    public function output(Request $request): string
    {
        $t = fn (string $key, array $replace = []): string => e(__('messages.openapi_company.'.$key, $replace));

        if (! OpenApiCompanySettings::configured()) {
            return '<p style="font-size:13px;color:var(--pn-muted);margin:0;">'.$t('not_configured').'</p>';
        }

        $environment = OpenApiCompanySettings::resolve()['sandbox'] === '1'
            ? '<strong style="color:var(--pn-warning, #b45309);">'.$t('environment_sandbox').'</strong>'
            : '<strong>'.$t('environment_live').'</strong>';

        $calls = OpenApiCompanyCall::where('created_at', '>=', now()->startOfMonth())
            ->selectRaw('endpoint, COUNT(*) as total')->groupBy('endpoint')->orderBy('endpoint')->pluck('total', 'endpoint');
        $summary = $calls->isEmpty()
            ? $t('no_calls')
            : $calls->map(fn ($total, $endpoint) => e($endpoint).': '.(int) $total)->implode(' · ');

        $html = '<p style="font-size:13px;margin:0 0 10px;">'.$environment.' · '.$t('calls_this_month').' '.$summary.'</p>'
            .'<form method="POST" action="'.route('admin.openapi-company.lookup').'" style="display:flex;gap:8px;align-items:end;margin:0 0 12px;">'
            .'<input type="hidden" name="_token" value="'.csrf_token().'">'
            .'<label style="font-size:13px;">'.$t('vat_code').'<br><input type="text" name="vat_code" class="form-control" required></label>'
            .'<button type="submit" class="btn btn-primary btn-sm" style="font-size:13px;padding:6px 14px;font-weight:600;">'.$t('lookup').'</button></form>';

        $result = session('openapi_company_result');
        if (is_array($result)) {
            if (! empty($result['missing'])) {
                return $html.'<p style="font-size:13px;margin:0;">'.$t('not_found', ['vat' => (string) $result['vatCode']]).'</p>';
            }
            $rows = '';
            foreach (['name', 'vatCode', 'taxCode', 'address', 'postcode', 'city', 'province', 'activityStatus', 'sdiCode', 'pec'] as $field) {
                $rows .= '<tr><td style="padding:4px 10px 4px 0;color:var(--pn-muted);">'.$t('field_'.$field).'</td><td style="padding:4px 0;">'.e((string) ($result[$field] ?? '—')).'</td></tr>';
            }
            $html .= '<table style="font-size:13px;border-collapse:collapse;">'.$rows.'</table>';
        }

        return $html;
    }
}
