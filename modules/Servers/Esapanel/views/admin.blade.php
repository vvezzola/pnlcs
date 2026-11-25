{{-- Esapanel section of the staff service page
     (Modules\Servers\Esapanel, drawn through ModuleScreens). The buttons
     are the module's customFunctions(), drawn by the page itself. --}}
@php
    $t = fn (string $key, array $with = []) => __('esapanel::messages.'.$key, $with);
    $time = fn ($iso) => $iso ? \Illuminate\Support\Carbon::parse($iso)->timezone(config('app.timezone'))->format(date_fmt().' H:i') : '—';
    $moduleName = $module !== '' ? (\Illuminate\Support\Facades\Lang::has('esapanel::messages.module.'.$module) ? $t('module.'.$module).' ('.strtoupper($module).')' : strtoupper($module)) : '—';
    $rows = [
        $t('field.status') => $t('state.'.$state).(filled($data['suspension_reason'] ?? null) ? ' · '.$data['suspension_reason'] : ''),
        $t('field.module') => $moduleName,
        $t('field.plan') => ($data['plan_label'] ?? '—').(filled($data['plan_id'] ?? null) ? ' #'.$data['plan_id'] : ''),
        $t('field.addons') => collect((array) ($data['addons'] ?? []))->map(fn ($a) => ($a['label'] ?? '?').' #'.($a['id'] ?? '?'))->implode(', ') ?: '—',
        $t('field.subscription') => $data['subscription_id'] ?? '—',
        $t('field.customer') => $data['customer_id'] ?? '—',
        $t('field.username') => $data['owner_username'] ?? '—',
        $t('field.panel') => $panelUrl ?? '—',
        $t('field.checked_at') => $time($data['checked_at'] ?? null),
    ];
@endphp
<div class="card">
    <div class="card-header"><span style="font-weight:600;">{{ $t('title') }}</span></div>
    <div class="card-body" style="padding:16px;">
        @if($state === 'none')
        <p style="margin:0;font-size:13px;color:var(--pn-muted);">{{ $t('admin.none') }}</p>
        @else
        <table style="width:100%;font-size:13px;border-collapse:collapse;">
            @foreach($rows as $label => $value)
            <tr><td style="padding:4px 12px 4px 0;color:var(--pn-muted);white-space:nowrap;width:1%;">{{ $label }}</td><td style="padding:4px 0;">{{ $value }}</td></tr>
            @endforeach
        </table>
        @endif

        @if($reseller)
        <p style="margin:14px 0 0;font-weight:600;font-size:13px;">{{ $t('reseller.title') }}</p>
        @if($limits)
        @include('esapanel::reseller')
        @endif
        @if(filled($data['limits_error'] ?? null))
        <p style="margin:8px 0 0;font-size:13px;color:#b45309;">{{ $t('reseller.read_error', ['error' => $data['limits_error']]) }}</p>
        @endif
        @endif

        @if($wfp)
        @php
            $gb = fn ($mb) => $mb === null ? '∞' : number_format($mb / 1024, 1, ',', '.').' GB';
        @endphp
        <p style="margin:14px 0 6px;font-weight:600;font-size:13px;">{{ $t('wfp.space') }}: {{ $gb($wfp['used_mb'] ?? 0) }} / {{ $gb($wfp['space_mb'] ?? null) }}@if(! empty($wfp['read_only'])) · <span style="color:#c43c35;">{{ $t('wfp.read_only_short') }}</span>@endif</p>
        <table style="width:100%;font-size:12.5px;border-collapse:collapse;">
            @forelse($wfp['accounts'] ?? [] as $account)
            <tr>
                <td style="padding:3px 10px 3px 0;">{{ $account['username'] }}</td>
                <td style="padding:3px 10px 3px 0;color:var(--pn-muted);">{{ $t('wfp.kind.'.($account['kind'] ?? 'device')) }} · {{ strtoupper(implode(', ', $account['protocols'] ?? [])) }}</td>
                <td style="padding:3px 10px 3px 0;">{{ $account['status'] ?? '' }}</td>
                <td style="padding:3px 0;{{ ! empty($account['late']) ? 'color:#c43c35;' : '' }}">{{ $time($account['last_upload_at'] ?? null) }}</td>
            </tr>
            @empty
            <tr><td style="color:var(--pn-muted);">{{ $t('wfp.no_accounts') }}</td></tr>
            @endforelse
        </table>
        @endif
        @if(filled($data['wfp_error'] ?? null))
        <p style="margin:12px 0 0;font-size:13px;color:#b45309;">{{ $t('wfp.read_error', ['error' => $data['wfp_error']]) }}</p>
        @endif

        @if(filled($data['last_error'] ?? null))
        <p style="margin:12px 0 0;font-size:13px;color:#c43c35;">{{ $t('admin.last_error', ['time' => $time($data['last_error_at'] ?? null), 'error' => $data['last_error']]) }}</p>
        @endif
    </div>
</div>
