{{-- Esapanel section of the customer's service page
     (Modules\Servers\Esapanel, drawn through ModuleScreens). --}}
@php
    $t = fn (string $key, array $with = []) => __('esapanel::messages.'.$key, $with);
    $moduleName = $module !== '' ? (\Illuminate\Support\Facades\Lang::has('esapanel::messages.module.'.$module) ? $t('module.'.$module) : strtoupper($module)) : '—';
    $serviceActive = strtolower((string) $service->status) === 'active';
@endphp
<style>
    .ep-card{margin-bottom:22px}
    .ep-head{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap}
    .ep-badge{font-size:11.5px;font-weight:700;padding:4px 11px;border-radius:999px;border:1px solid var(--border);background:var(--bg);color:var(--muted)}
    .ep-badge.active{background:rgba(16,185,129,.12);border-color:rgba(16,185,129,.35);color:#059669}
    .ep-badge.suspended{background:rgba(251,191,36,.14);border-color:rgba(251,191,36,.4);color:#b45309}
    .ep-body{padding:18px 20px}
    .ep-lead{margin:0 0 10px;color:var(--muted);font-size:13.5px;line-height:1.55}
    .ep-note{margin-top:12px;padding:11px 14px;border-radius:10px;font-size:13px;line-height:1.5;border:1px solid var(--border);background:var(--bg)}
    .ep-note.warn{border-color:rgba(251,191,36,.45);background:rgba(251,191,36,.1)}
    .ep-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:16px}
</style>

<div class="sv-panel ep-card" id="esapanel-service">
    <div class="sv-ph ep-head">
        <span><i class="ri-server-line"></i> {{ $moduleName !== '—' ? $moduleName : $t('title') }}</span>
        <span class="ep-badge {{ $state }}">{{ $t('state.'.$state) }}</span>
    </div>

    @if($state === 'none')
    <div class="ep-body"><p class="ep-lead" style="margin:0">{{ $t('client.preparing') }}</p></div>

    @elseif($state === 'ended')
    <div class="ep-body"><p class="ep-lead" style="margin:0">{{ $t('client.ended') }}</p></div>

    @else
    <ul class="sv-dl">
        <li><span class="k">{{ $t('field.plan') }}</span><span class="v">{{ $data['plan_label'] ?? '—' }}</span></li>
        @if(! empty($data['addons']))
        <li><span class="k">{{ $t('field.addons') }}</span><span class="v">{{ collect($data['addons'])->pluck('label')->filter()->implode(', ') }}</span></li>
        @endif
        @if(! empty($data['owner_username']))
        <li><span class="k">{{ $t('field.username') }}</span><span class="v"><span class="sv-code">{{ $data['owner_username'] }}</span></span></li>
        @endif
        @if($panelUrl)
        <li><span class="k">{{ $t('field.panel') }}</span><span class="v">{{ $panelUrl }}</span></li>
        @endif
    </ul>

    <div class="ep-body" style="padding-top:0">
        @if($state === 'suspended')
        <div class="ep-note warn">{{ $t('client.suspended') }}</div>
        @endif

        @if($wfp)
        @include('esapanel::wfp-client')
        @endif

        <p class="ep-lead" style="margin-top:12px">{{ ! empty($data['owner_username']) ? $t('client.panel_lead') : $t('client.panel_existing') }}</p>

        @if($serviceActive && $panelUrl)
        <div class="ep-actions">
            <a href="{{ route('client.services.login', $service) }}" target="_blank" rel="noopener" class="pn-btn pn-btn-sm pn-btn-primary"><i class="ri-external-link-line"></i> {{ $t('action.open_panel') }}</a>
        </div>
        @endif
    </div>
    @endif
</div>
