{{-- Esapanel licence section of the staff service page
     (Modules\Servers\EsapanelLicense, drawn through ModuleScreens). The
     buttons are the module's customFunctions(), drawn by the page itself. --}}
@php
    $t = fn (string $key, array $with = []) => __('esapanel-license::messages.'.$key, $with);
    $day = fn ($date) => $date ? \Illuminate\Support\Carbon::parse($date)->format(date_fmt()) : '—';
    $time = fn ($iso) => $iso ? \Illuminate\Support\Carbon::parse($iso)->timezone(config('app.timezone'))->format(date_fmt().' H:i') : '—';
    $rows = [
        $t('field.code') => $data['code'] ?? '—',
        $t('field.status') => $t('state.'.$state).(! empty($data['trial']) ? ' · Trial' : ''),
        $t('field.valid_until') => $day($data['valid_until'] ?? null),
        $t('field.level') => ucfirst((string) ($data['tier'] ?? '')) ?: '—',
        $t('field.modules') => strtoupper(implode(', ', (array) ($data['modules'] ?? []))) ?: '—',
        $t('field.subscription') => $data['subscription_id'] ?? '—',
        $t('field.customer') => $data['customer_id'] ?? '—',
        $t('field.installation') => $installation['hostname'] ?? $t('client.not_bound'),
        $t('field.version') => $installation['version'] ?? '—',
        $t('field.bound_at') => $time($installation['bound_at'] ?? null),
        $t('field.last_check') => $time($installation['last_check_at'] ?? null),
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

        @if(! empty($data['pending_transfer']))
        <p style="margin:12px 0 0;font-size:13px;color:#b45309;">{{ $t('admin.transfer') }}</p>
        @endif
        @if(filled($data['configuration_error'] ?? null))
        <p style="margin:12px 0 0;font-size:13px;color:#c43c35;">{{ $t('admin.configuration_error', ['error' => $data['configuration_error']]) }}</p>
        @endif
        @if(filled($data['last_error'] ?? null))
        <p style="margin:12px 0 0;font-size:13px;color:#c43c35;">{{ $t('admin.last_error', ['time' => $time($data['last_error_at'] ?? null), 'error' => $data['last_error']]) }}</p>
        @endif
    </div>
</div>
