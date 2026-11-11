@extends("admin.layouts.app")

@section("title", __("admin.ssl_module_configuration"))

@section("content")
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0">{{ __('admin.ssl_modules.title') }}</h1>
    <a href="{{ route('admin.config.servers') }}" class="btn btn-secondary">{{ __('common.actions.back') }}</a>
</div>

@foreach($modules as $name => $module)
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0">{{ ucfirst($name) }}</h5>
        <form method="POST" action="{{ route('admin.config.testSslConnection', $name) }}" class="d-inline">
            @csrf
            <button type="submit" class="btn btn-sm btn-outline-primary">{{ __('common.actions.test_connection') }}</button>
        </form>
    </div>
    <div class="card-body">
        <form method="POST" action="{{ route('admin.config.updateSslModuleSettings', $name) }}">
            @csrf
            @foreach($module->getConfigFields() as $key => $field)
                <div class="mb-3">
                    <label class="form-label">{{ $field['label'] }}</label>
                    @if(($field['type'] ?? 'text') === 'checkbox')
                        <div class="form-check">
                            <input type="hidden" name="settings[{{ $key }}]" value="0">
                            <input type="checkbox" name="settings[{{ $key }}]" class="form-check-input" value="1"
                                {{ ($settings[$name][$key] ?? $field['default'] ?? '0') === '1' ? 'checked' : '' }}>
                            <label class="form-check-label">{{ __('common.status.enabled') }}</label>
                        </div>
                    @elseif(($field['type'] ?? 'text') === 'select')
                        <select name="settings[{{ $key }}]" class="form-control">
                            @foreach(($field['options'] ?? []) as $optValue => $optLabel)
                                <option value="{{ $optValue }}" @selected((string) ($settings[$name][$key] ?? '') === (string) $optValue)>{{ $optLabel }}</option>
                            @endforeach
                        </select>
                    @elseif(($field['type'] ?? 'text') === 'table')
                        <div class="table-responsive" style="max-height:320px;overflow-y:auto;">
                            <table class="table table-sm mb-0">
                                <thead><tr>@foreach(($field['headers'] ?? []) as $header)<th>{{ $header }}</th>@endforeach</tr></thead>
                                <tbody>
                                    @foreach(($field['rows'] ?? []) as $row)
                                        <tr>@foreach($row as $cell)<td>{{ $cell }}</td>@endforeach</tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @elseif(($field['type'] ?? 'text') === 'password')
                        {{-- Never echoed back: blank keeps the stored password. --}}
                        <input type="password" name="settings[{{ $key }}]" class="form-control" value="" autocomplete="new-password"
                            placeholder="{{ ($settings[$name][$key] ?? '') !== '' ? __('admin.settings.smtp_password_keep') : '' }}"
                            {{ !empty($field['required']) && ($settings[$name][$key] ?? '') === '' ? 'required' : '' }}>
                    @else
                        <input type="text" name="settings[{{ $key }}]" class="form-control"
                            value="{{ $settings[$name][$key] ?? '' }}"
                            {{ !empty($field['required']) ? 'required' : '' }}>
                    @endif
                </div>
            @endforeach
            <button type="submit" class="btn btn-primary">{{ __('admin.ssl_modules.save_settings') }}</button>
        </form>
    </div>
</div>
@endforeach
@endsection
