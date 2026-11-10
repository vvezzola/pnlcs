@props(['order'])
{{-- What the domain owner has to publish for an HTTP or DNS validation, when
     the SSL module stored it on the order (config_data.dcv). --}}
@php($dcv = $order->config_data['dcv'] ?? null)
@if(is_array($dcv) && ! in_array($order->status, ['Completed', 'Cancelled', 'Revoked', 'Expired'], true))
<div class="card mb-4">
    <div class="card-header"><h5 class="mb-0">{{ __('common.ssl_dcv.title') }}</h5></div>
    <div class="card-body">
        @if(($dcv['method'] ?? '') === 'HTTP')
            <p class="mb-1">{{ __('common.ssl_dcv.http_intro') }}</p>
            <p><code class="user-select-all">{{ $dcv['url'] ?? '' }}</code></p>
            <label class="text-muted small">{{ __('common.ssl_dcv.file_content') }}</label>
            <pre class="bg-light border rounded p-2 user-select-all mb-3">{{ $dcv['content'] ?? '' }}</pre>
        @elseif(($dcv['method'] ?? '') === 'DNS')
            <p>{{ __('common.ssl_dcv.dns_intro') }}</p>
            <table class="table table-sm mb-3">
                <tr><th class="text-muted small fw-normal">{{ __('common.ssl_dcv.record_name') }}</th><td><code class="user-select-all">{{ $dcv['name'] ?? '' }}</code></td></tr>
                <tr><th class="text-muted small fw-normal">{{ __('common.ssl_dcv.record_type') }}</th><td><code>{{ $dcv['type'] ?? 'CNAME' }}</code></td></tr>
                <tr><th class="text-muted small fw-normal">{{ __('common.ssl_dcv.record_value') }}</th><td><code class="user-select-all">{{ $dcv['value'] ?? '' }}</code></td></tr>
            </table>
        @endif
        <p class="text-muted small mb-0">{{ __('common.ssl_dcv.after') }}</p>
    </div>
</div>
@endif
