{{-- ===== TOP BAR (Esalogica) ===== --}}
@php
    $esa = (require base_path('themes/esalogica/strings.php'))[app()->getLocale() === 'it' ? 'it' : 'en']['topbar'];
@endphp
<div class="top-bar">
    <div class="container">
        <div class="top-bar__inner">
            <div class="top-bar__left">
                @if(!empty($brandEmail))
                <a href="mailto:{{ $brandEmail }}" class="top-bar__item"><i class="ri-mail-line"></i> {{ $brandEmail }}</a>
                <div class="top-bar__divider"></div>
                @endif
                <a href="{{ url('/client/contact') }}" class="top-bar__item"><i class="ri-headphone-line"></i> {{ $esa['contact'] }}</a>
                <div class="top-bar__divider"></div>
                <a href="{{ url('/client/tickets/create') }}" class="top-bar__item"><i class="ri-ticket-line"></i> {{ $esa['ticket'] }}</a>
            </div>
            <div class="top-bar__right">
                <a href="{{ route('client.login') }}" class="top-bar__item"><i class="ri-user-line"></i> {{ $esa['account'] }}</a>
                <div class="top-bar__divider"></div>
                <a href="{{ route('client.register') }}" class="top-bar__item"><i class="ri-user-add-line"></i> {{ $esa['register'] }}</a>
                @if(isset($activeLanguages) && $activeLanguages->count() > 1)
                <div class="top-bar__divider"></div>
                <details class="top-bar__language">
                    <summary class="top-bar__item top-bar__language-toggle">
                        <i class="ri-global-line"></i> {{ $currentLocaleName ?? __('client.topbar.language') }}
                    </summary>
                    <div class="top-bar__language-menu">
                        @foreach($activeLanguages as $language)
                            <a href="{{ \App\Support\LocaleUrl::switchTo($language->code) }}" class="top-bar__language-option {{ $language->code === ($currentLocale ?? app()->getLocale()) ? 'top-bar__language-option--active' : '' }}">
                                {{ $language->native_name }}
                            </a>
                        @endforeach
                    </div>
                </details>
                @endif
            </div>
        </div>
    </div>
</div>
