@props(['order', 'showKey' => true])

{{--
    The three boxes a control panel asks for when a certificate is pasted in
    as text (Plesk: "Upload the certificate as text"), in the same order, each
    with its own copy button, so installing it is three copy-and-paste steps.
--}}
@if ($order->isCompleted() && filled($order->cert))
    @php
        $sslInstallFields = array_filter([
            'key' => $showKey ? $order->private_key : null,
            'cert' => $order->cert,
            'ca' => $order->ca_cert,
        ], 'filled');
    @endphp
    <div class="card mb-4" data-ssl-install>
        <div class="card-header"><h5 class="mb-0">{{ __('common.ssl_install.title') }}</h5></div>
        <div class="card-body">
            <p class="text-muted small">{{ __('common.ssl_install.intro') }}</p>

            @foreach ($sslInstallFields as $field => $pem)
                <div class="mb-3">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <label class="form-label small fw-semibold mb-0" for="ssl-install-{{ $field }}">{{ __('common.ssl_install.'.$field) }}</label>
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-ssl-copy="ssl-install-{{ $field }}">
                            <i class="fas fa-copy me-1"></i>{{ __('common.ssl_install.copy') }}
                        </button>
                    </div>
                    <textarea id="ssl-install-{{ $field }}" class="form-control font-monospace small" rows="4" readonly spellcheck="false">{{ trim($pem) }}</textarea>
                </div>
            @endforeach

            @if ($showKey && blank($order->private_key))
                <p class="small text-muted mb-0">{{ __('common.ssl_install.key_elsewhere') }}</p>
            @endif
        </div>
    </div>

    <script>
        document.querySelectorAll('[data-ssl-copy]').forEach((button) => {
            button.addEventListener('click', () => {
                const field = document.getElementById(button.dataset.sslCopy);
                field.select();
                (navigator.clipboard ? navigator.clipboard.writeText(field.value) : Promise.reject())
                    .catch(() => document.execCommand('copy'));
                const label = button.innerHTML;
                button.textContent = @js(__('common.ssl_install.copied'));
                setTimeout(() => { button.innerHTML = label; }, 2000);
            });
        });
    </script>
@endif
