{{-- Esapanel licence section of the customer's service page
     (Modules\Servers\EsapanelLicense, drawn through ModuleScreens). --}}
@php
    $t = fn (string $key, array $with = []) => __('esapanel-license::messages.'.$key, $with);
    $day = fn ($date) => $date ? \Illuminate\Support\Carbon::parse($date)->format(date_fmt()) : '—';
    $time = fn ($iso) => $iso ? \Illuminate\Support\Carbon::parse($iso)->timezone(config('app.timezone'))->format(date_fmt().' H:i') : '—';
    $serviceActive = strtolower((string) $service->status) === 'active';
@endphp
<style>
    .el-card{margin-bottom:22px}
    .el-head{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap}
    .el-badge{font-size:11.5px;font-weight:700;padding:4px 11px;border-radius:999px;border:1px solid var(--border);background:var(--bg);color:var(--muted)}
    .el-badge.active{background:rgba(16,185,129,.12);border-color:rgba(16,185,129,.35);color:#059669}
    .el-badge.suspended,.el-badge.expired{background:rgba(251,191,36,.14);border-color:rgba(251,191,36,.4);color:#b45309}
    .el-body{padding:18px 20px}
    .el-lead{margin:0 0 10px;color:var(--muted);font-size:13.5px;line-height:1.55}
    .el-cmd{display:flex;gap:8px;align-items:stretch;flex-wrap:wrap;margin-bottom:10px}
    .el-cmd code{flex:1 1 280px;min-width:0;font-family:ui-monospace,Menlo,monospace;font-size:12.5px;background:#0f172a;color:#e2e8f0;padding:11px 13px;border-radius:9px;overflow-x:auto;white-space:nowrap}
    .el-note{margin-top:12px;padding:11px 14px;border-radius:10px;font-size:13px;line-height:1.5;border:1px solid var(--border);background:var(--bg)}
    .el-note.warn{border-color:rgba(251,191,36,.45);background:rgba(251,191,36,.1)}
    .el-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:16px}
    .el-code{display:inline-flex;align-items:center;gap:8px;flex-wrap:wrap;justify-content:flex-end}
</style>

<div class="sv-panel el-card" id="esapanel-license">
    <div class="sv-ph el-head">
        <span><i class="ri-shield-keyhole-line"></i> {{ $t('title') }}</span>
        <span class="el-badge {{ $state }}">{{ $t('state.'.$state) }}</span>
    </div>

    @if($state === 'none')
    <div class="el-body"><p class="el-lead" style="margin:0">{{ $t('client.preparing') }}</p></div>

    @elseif($state === 'ended')
    <div class="el-body"><p class="el-lead" style="margin:0">{{ $t('client.ended') }}</p></div>

    @else
    <ul class="sv-dl">
        <li><span class="k">{{ $t('field.code') }}</span><span class="v el-code">
            <span class="sv-code" id="el-code">{{ $data['code'] ?? '—' }}</span>
            @if(! empty($data['code']))
            <button type="button" class="pn-btn pn-btn-sm" data-el-copy="el-code" data-copied="{{ $t('action.copied') }}">{{ $t('action.copy') }}</button>
            @endif
        </span></li>
        <li><span class="k">{{ $t('field.valid_until') }}</span><span class="v">{{ $day($data['valid_until'] ?? null) }}</span></li>
        <li><span class="k">{{ $t('field.level') }}</span><span class="v">{{ ucfirst((string) ($data['tier'] ?? '')) ?: '—' }}</span></li>
        <li><span class="k">{{ $t('field.modules') }}</span><span class="v">{{ strtoupper(implode(', ', (array) ($data['modules'] ?? []))) ?: '—' }}</span></li>
        @if($installation)
        <li><span class="k">{{ $t('field.installation') }}</span><span class="v">{{ $installation['hostname'] ?? '—' }}</span></li>
        <li><span class="k">{{ $t('field.version') }}</span><span class="v">{{ $installation['version'] ?? '—' }}</span></li>
        <li><span class="k">{{ $t('field.last_check') }}</span><span class="v">{{ $time($installation['last_check_at'] ?? null) }}</span></li>
        @endif
    </ul>

    <div class="el-body" style="padding-top:0">
        @if(! empty($data['trial']))
        <div class="el-note">{{ $t('client.trial') }}</div>
        @endif
        @if($state === 'suspended')
        <div class="el-note warn">{{ $t('client.suspended') }}</div>
        @elseif($state === 'expired')
        <div class="el-note warn">{{ $t('client.expired') }}</div>
        @endif
        @if(! empty($data['pending_transfer']))
        <div class="el-note warn">{{ $t('client.transfer') }}</div>
        @endif
        @if(filled($data['last_error'] ?? null) || filled($data['configuration_error'] ?? null))
        <div class="el-note warn">{{ $t('client.needs_help') }}</div>
        @endif

        @if($command && ! $installation)
        <div class="el-note">
            <p class="el-lead">{{ $t('client.install_lead') }}</p>
            <div class="el-cmd">
                <code id="el-command">{{ $command }}</code>
                <button type="button" class="pn-btn pn-btn-sm" data-el-copy="el-command" data-copied="{{ $t('action.copied') }}">{{ $t('action.copy') }}</button>
            </div>
            <p class="el-lead" style="margin:0">{{ $t('client.install_existing') }}</p>
        </div>
        @endif

        @if($serviceActive)
        <div class="el-actions">
            <form method="POST" action="{{ route('client.services.module-function', [$service, 'checkNow']) }}">@csrf
                <button type="submit" class="pn-btn pn-btn-sm pn-btn-primary"><i class="ri-refresh-line"></i> {{ $t('action.check_now') }}</button>
            </form>
            @if($installation)
            <form method="POST" action="{{ route('client.services.module-function', [$service, 'unbindServer']) }}" onsubmit="return pnConfirm(event, @js($t('client.unbind_confirm')))">@csrf
                <button type="submit" class="pn-btn pn-btn-sm">{{ $t('action.unbind') }}</button>
            </form>
            @endif
        </div>
        @endif
    </div>
    @endif
</div>
<script>
document.querySelectorAll('#esapanel-license [data-el-copy]').forEach(function (button) {
    button.addEventListener('click', function () {
        navigator.clipboard.writeText(document.getElementById(button.dataset.elCopy).innerText).then(function () {
            button.textContent = button.dataset.copied;
        });
    });
});
</script>
