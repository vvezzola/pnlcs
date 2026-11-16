{{-- Aruba Business section of the staff service page
     (Modules\Servers\ArubaBusiness, drawn through ModuleScreens). --}}
@php
    $t = fn (string $key, array $with = []) => __('aruba-business::messages.'.$key, $with);
    $day = fn ($date) => $date ? \Illuminate\Support\Carbon::parse($date)->format(date_fmt()) : '—';
    $time = fn ($iso) => $iso ? \Illuminate\Support\Carbon::parse($iso)->timezone(config('app.timezone'))->format(date_fmt().' H:i') : '—';
    $rows = [
        $t($isMailbox ? 'field.account' : 'field.domain') => $data['aruba_account'] ?? '—',
        $t('field.article') => $data['aruba_article'] ?? '—',
        $t('field.state') => $data['aruba_state'] ?? '—',
        $t('field.activated') => $day($data['aruba_activated'] ?? null),
        $t('field.expires') => $day($data['aruba_expires'] ?? null),
        $t('field.autorenew') => $t('autorenew.'.(empty($data['aruba_autorenew']) ? 'off' : 'on')),
        $t('field.renewal_price') => ($data['aruba_renewal_price'] ?? '') ?: '—',
        $t('field.service_id') => $data['aruba_service_id'] ?? '—',
        $t('field.checked_at') => $time($data['checked_at'] ?? null),
    ];
@endphp
<div class="card">
    <div class="card-header"><span style="font-weight:600;">{{ $t('title') }}</span></div>
    <div class="card-body" style="padding:16px;">
        @if(! $linked)
        <p style="margin:0;font-size:13px;color:var(--pn-muted);">{{ $t('admin.not_linked') }}</p>
        @else
        <table style="width:100%;font-size:13px;border-collapse:collapse;">
            @foreach($rows as $label => $value)
            <tr><td style="padding:4px 12px 4px 0;color:var(--pn-muted);white-space:nowrap;width:1%;">{{ $label }}</td><td style="padding:4px 0;">{{ $value }}</td></tr>
            @endforeach
        </table>
        @endif
        @foreach($warnings as $warning)
        <p style="margin:12px 0 0;font-size:13px;color:#c43c35;">{{ $warning }}</p>
        @endforeach
    </div>
</div>
