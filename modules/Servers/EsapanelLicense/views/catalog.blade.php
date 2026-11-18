@extends('admin.layouts.app')
@section('title', __('esapanel-license::messages.page.title'))
@section('content')
@php
    use Modules\Servers\EsapanelLicense\EsapanelCatalog;
    $t = fn (string $key, array $replace = []) => __('esapanel-license::messages.page.'.$key, $replace);
    $th = 'padding:8px 6px;text-align:left;font-size:12px;color:#555;border-bottom:1px solid #e5e7eb;white-space:nowrap;vertical-align:bottom;';
    $td = 'padding:6px;border-bottom:1px solid #f0f0f0;font-size:13px;vertical-align:top;';
    $columns = [
        'company_small' => $t('col_company_small'), 'company_large' => $t('col_company_large'),
        'integrator_30' => 'Integrator 30', 'integrator_60' => 'Integrator 60', 'integrator_100' => 'Integrator 100', 'provider' => 'Provider',
    ];
    $sizeOf = function (string $module, string $column) {
        $sizes = array_values(EsapanelCatalog::MODULES[$module]['company']);
        $index = $column === 'company_small' ? 0 : 1;

        return __('esapanel-license::messages.catalog.size.'.$sizes[$index]);
    };
    $selectedGroup = old('group_id', optional($groups->firstWhere('slug', 'esapanel'))->id);
@endphp

<div class="card" style="margin-bottom:15px;">
    <div class="card-header"><strong>{{ $t('title') }}</strong></div>
    <div class="card-body" style="font-size:13.5px;line-height:1.55;">
        <p style="margin-top:0;">{{ $t('intro') }}</p>
        <ol style="margin:0;padding-left:20px;">
            <li>{!! $t('step_server') !!}</li>
            <li>{{ $t('step_group') }}</li>
            <li>{{ $t('step_prices') }}</li>
            <li>{{ $t('step_preview') }}</li>
        </ol>
    </div>
</div>

@if($servers->isEmpty())
    <div class="alert alert-warning">{!! $t('no_server') !!}</div>
@else
<form method="POST" action="{{ route('admin.esapanel-catalog.store') }}">
    @csrf
    <div class="card" style="margin-bottom:15px;">
        <div class="card-body" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:15px;align-items:end;">
            <div class="form-group" style="margin:0;">
                <label class="form-label" for="ep-server">{{ $t('server') }}</label>
                <select id="ep-server" name="server_id" class="form-control">
                    @foreach($servers as $server)
                        <option value="{{ $server->id }}" @selected((string) old('server_id') === (string) $server->id)>{{ $server->name }} ({{ $server->hostname }})</option>
                    @endforeach
                </select>
            </div>
            <div class="form-group" style="margin:0;">
                <label class="form-label" for="ep-group">{{ $t('group') }}</label>
                <select id="ep-group" name="group_id" class="form-control">
                    @foreach($groups as $group)
                        <option value="{{ $group->id }}" @selected((string) $selectedGroup === (string) $group->id)>{{ $group->name }}</option>
                    @endforeach
                </select>
            </div>
            <label style="display:flex;gap:8px;align-items:center;font-size:13px;margin:0;">
                <input type="hidden" name="visible" value="0">
                <input type="checkbox" name="visible" value="1" @checked(old('visible'))>
                <span>{{ $t('visible') }}<br><small style="color:#777;">{{ $t('visible_hint') }}</small></span>
            </label>
        </div>
    </div>

    <div class="card" style="margin-bottom:15px;">
        <div class="card-header"><strong>{{ $t('prices', ['currency' => $currency?->code ?? '']) }}</strong></div>
        <div class="card-body" style="overflow-x:auto;">
            <p style="margin-top:0;font-size:12.5px;color:#666;">{{ $t('prices_hint') }}</p>
            <table style="width:100%;border-collapse:collapse;min-width:900px;">
                <thead>
                    <tr>
                        <th style="{{ $th }}">{{ $t('module') }}</th>
                        @foreach($columns as $column => $label)
                            <th style="{{ $th }}">{{ $label }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach(array_keys(EsapanelCatalog::MODULES) as $module)
                        <tr>
                            <td style="{{ $td }}font-weight:600;white-space:nowrap;">{{ EsapanelCatalog::moduleLabel($module) }}</td>
                            @foreach($columns as $column => $label)
                                <td style="{{ $td }}">
                                    @if(str_starts_with($column, 'company'))
                                        <div style="font-size:11px;color:#777;margin-bottom:2px;">{{ $sizeOf($module, $column) }}</div>
                                    @endif
                                    @foreach(['monthly' => $t('per_month'), 'annually' => $t('per_year')] as $cycle => $cycleLabel)
                                        <input type="text" inputmode="decimal" class="form-control" style="font-size:12px;padding:3px 6px;margin-bottom:3px;"
                                            name="prices[{{ $module }}][{{ $column }}][{{ $cycle }}]"
                                            value="{{ $prices[$module][$column][$cycle] ?? '' }}"
                                            placeholder="{{ $cycleLabel }}" aria-label="{{ EsapanelCatalog::moduleLabel($module) }} {{ $label }} {{ $cycleLabel }}">
                                    @endforeach
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
            @error('prices.*')<div class="text-sm" style="color:var(--danger);margin-top:6px;">{{ $message }}</div>@enderror
        </div>
    </div>

    <div style="display:flex;gap:10px;margin-bottom:15px;">
        <button type="submit" name="action" value="preview" class="btn btn-default"><i class="fas fa-eye"></i> {{ $t('preview') }}</button>
        <button type="submit" name="action" value="write" class="btn btn-primary"><i class="fas fa-check"></i> {{ $t('write') }}</button>
    </div>
</form>
@endif

@if($result)
    <div class="card" style="margin-bottom:15px;">
        <div class="card-header"><strong>
            @if(! $result['ok']) {{ $t('result_error') }}
            @elseif($result['changes'] === 0) {{ $t('result_none') }}
            @elseif($result['written']) {{ $t('result_written', ['count' => $result['changes']]) }}
            @else {{ $t('result_preview', ['count' => $result['changes']]) }}
            @endif
        </strong></div>
        <div class="card-body"><pre style="margin:0;font-size:12px;white-space:pre-wrap;">{{ implode("\n", $result['lines']) }}</pre></div>
    </div>
@endif
@endsection
