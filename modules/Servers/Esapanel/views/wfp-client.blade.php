{{-- WFP (EsaBackup) part of the customer's Esapanel section: the space, the
     connection data and the accounts, with what the customer can do to them.
     Included from client.blade.php, which defines $t. --}}
@php
    $gb = fn ($mb) => $mb === null ? '∞' : number_format($mb / 1024, 1, ',', '.').' GB';
    $time = fn ($iso) => $iso ? \Illuminate\Support\Carbon::parse($iso)->timezone(config('app.timezone'))->format(date_fmt().' H:i') : '—';
    $used = (int) ($wfp['used_mb'] ?? 0);
    $space = $wfp['space_mb'] ?? null;
    $percent = $space ? min(100, (int) round($used * 100 / max(1, (int) $space))) : 0;
    $server = (array) ($wfp['server'] ?? []);
    $made = session('esapanel_password');
    $canChange = strtolower((string) $service->status) === 'active';
@endphp
<style>
    .ep-bar{height:8px;border-radius:999px;background:var(--border);overflow:hidden;margin:6px 0 4px}
    .ep-bar span{display:block;height:100%;background:#405189}
    .ep-bar.full span{background:#c43c35}
    .ep-table{width:100%;border-collapse:collapse;font-size:13px;margin-top:8px}
    .ep-table th{text-align:left;font-weight:600;color:var(--muted);padding:6px 8px 6px 0;border-bottom:1px solid var(--border)}
    .ep-table td{padding:7px 8px 7px 0;border-bottom:1px solid var(--border);vertical-align:top}
    .ep-late{color:#c43c35;font-weight:600}
    .ep-inline{display:inline}
    .ep-form{display:grid;gap:10px;margin-top:10px;max-width:520px}
    .ep-form label{font-size:13px}
    .ep-checks{display:flex;gap:14px;flex-wrap:wrap}
</style>

<div class="ep-note">
    <strong>{{ $t('wfp.space') }}</strong>: {{ ($wfp['used_mb'] ?? null) === null ? $t('wfp.not_measured') : $gb($used) }} / {{ $gb($space) }}
    <div class="ep-bar {{ $percent >= 95 || ! empty($wfp['read_only']) ? 'full' : '' }}"><span style="width:{{ $percent }}%"></span></div>
    @if(! empty($wfp['read_only']))
    <span class="ep-late">{{ $t('wfp.read_only') }}</span>
    @endif
</div>

@if($made)
<div class="ep-note warn">
    <p class="ep-lead" style="margin:0 0 6px">{{ $t('wfp.password_once') }}</p>
    <div>{{ $t('field.username') }}: <span class="sv-code">{{ $made['username'] }}</span></div>
    <div>{{ $t('wfp.password') }}: <span class="sv-code">{{ $made['password'] }}</span></div>
</div>
@endif

<div class="ep-note">
    <strong>{{ $t('wfp.connect') }}</strong>
    <ul class="sv-dl" style="margin-top:6px">
        <li><span class="k">{{ $t('wfp.host') }}</span><span class="v"><span class="sv-code">{{ $server['host'] ?? '—' }}</span></span></li>
        @foreach(['rsync' => 'rsync_port', 'sftp' => 'sftp_port', 'ftps' => 'ftps_port', 'webdav' => 'webdav_port'] as $protocol => $port)
        @if(! empty($server[$port]))
        <li><span class="k">{{ strtoupper($protocol) }}</span><span class="v">{{ $t('wfp.port', ['port' => $server[$port]]) }}</span></li>
        @endif
        @endforeach
    </ul>
</div>

<div class="ep-note">
    <strong>{{ $t('wfp.accounts') }}</strong>
    @if(empty($wfp['accounts']))
    <p class="ep-lead" style="margin:6px 0 0">{{ $t('wfp.no_accounts') }}</p>
    @else
    <table class="ep-table">
        <tr><th>{{ $t('field.username') }}</th><th>{{ $t('wfp.protocols') }}</th><th>{{ $t('wfp.last_upload') }}</th><th></th></tr>
        @foreach($wfp['accounts'] as $account)
        <tr>
            <td><span class="sv-code">{{ $account['username'] }}</span><br><span style="color:var(--muted);font-size:12px">{{ $t('wfp.kind.'.($account['kind'] ?? 'device')) }}@if(filled($account['description'] ?? null)) · {{ $account['description'] }}@endif</span></td>
            <td>{{ strtoupper(implode(', ', $account['protocols'] ?? [])) }}</td>
            <td class="{{ ! empty($account['late']) ? 'ep-late' : '' }}">{{ $time($account['last_upload_at'] ?? null) }}@if(! empty($account['late']))<br>{{ $t('wfp.late') }}@endif</td>
            <td style="white-space:nowrap">
                @if($canChange)
                <form class="ep-inline" method="POST" action="{{ route('client.services.esapanel.wfp.accounts.act', [$service, $account['id'], 'password']) }}" onsubmit="return pnConfirm(event, @js($t('wfp.password_confirm')))">@csrf
                    <button type="submit" class="pn-btn pn-btn-sm">{{ $t('wfp.action.password') }}</button>
                </form>
                @if(($account['status'] ?? '') === 'suspended')
                <form class="ep-inline" method="POST" action="{{ route('client.services.esapanel.wfp.accounts.act', [$service, $account['id'], 'resume']) }}">@csrf
                    <button type="submit" class="pn-btn pn-btn-sm">{{ $t('wfp.action.resume') }}</button>
                </form>
                @else
                <form class="ep-inline" method="POST" action="{{ route('client.services.esapanel.wfp.accounts.act', [$service, $account['id'], 'suspend']) }}">@csrf
                    <button type="submit" class="pn-btn pn-btn-sm">{{ $t('wfp.action.suspend') }}</button>
                </form>
                @endif
                @endif
            </td>
        </tr>
        @endforeach
    </table>
    @endif

    @if($canChange && (($wfp['accounts_limit'] ?? null) === null || count($wfp['accounts'] ?? []) < (int) $wfp['accounts_limit']))
    <form class="ep-form" method="POST" action="{{ route('client.services.esapanel.wfp.accounts.store', $service) }}">@csrf
        <strong style="margin-top:8px">{{ $t('wfp.new_account') }}</strong>
        <label>{{ $t('wfp.name') }}<br><input class="pn-input" name="name" required maxlength="26" pattern="[a-z0-9]([a-z0-9\-]{0,24}[a-z0-9])?" title="{{ $t('wfp.name_rule') }}"></label>
        <span style="font-size:12px;color:var(--muted)">{{ $t('wfp.name_rule') }}</span>
        <label>{{ $t('wfp.description') }}<br><input class="pn-input" name="description" maxlength="255"></label>
        <label>{{ $t('wfp.kind_label') }}<br>
            <select class="pn-input" name="kind">
                <option value="device">{{ $t('wfp.kind.device') }}</option>
                <option value="person">{{ $t('wfp.kind.person') }}</option>
            </select>
        </label>
        <div class="ep-checks">
            @foreach(\Modules\Servers\Esapanel\WfpPart::PROTOCOLS as $protocol)
            <label><input type="checkbox" name="protocols[]" value="{{ $protocol }}" @checked($protocol === 'sftp')> {{ strtoupper($protocol) }}</label>
            @endforeach
        </div>
        <div><button type="submit" class="pn-btn pn-btn-sm pn-btn-primary">{{ $t('wfp.action.create') }}</button></div>
    </form>
    @endif
</div>
