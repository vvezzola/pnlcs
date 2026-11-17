{{-- ===== MAIN NAVIGATION (Esalogica) =====
     Only what Esalogica sells: no WordPress, VDS or dedicated server entries
     and no launch discount. The VPS entry appears once VPS products are on
     sale, the guides once the knowledge base is switched on. --}}
@php
    $esa = (require base_path('themes/esalogica/strings.php'))[app()->getLocale() === 'it' ? 'it' : 'en']['nav'];
    $sellsVps = isset($products) && $products->contains(fn ($p) => $p->type === 'vps' || ($p->group && str_contains(strtolower($p->group->name), 'vps')));
@endphp
<nav class="main-nav" x-data="{ scrolled: false }" @scroll.window="scrolled = (window.scrollY > 10)" :class="{ 'scrolled': scrolled }">
    <div class="container">
        <div class="main-nav__inner">
            <a href="{{ route('home') }}" class="main-nav__brand">
                @if(!empty($customLogo))
                    <img src="{{ $customLogo }}" alt="{{ $brandName ?? 'Esalogica' }}">
                @else
                    {{ $brandName ?? 'Esalogica' }}<span class="main-nav__brand-dot"></span>
                @endif
            </a>

            <div class="main-nav__menu">
                <div class="main-nav__item">
                    <span class="main-nav__link">{{ $esa['domains'] }} <i class="ri-arrow-down-s-line"></i></span>
                    <div class="main-nav__dropdown">
                        <a href="{{ url('/client/domain-search') }}" class="main-nav__dropdown-link"><i class="ri-search-line"></i> {{ $esa['domain_register'] }}</a>
                        <a href="{{ url('/client/domain-search') }}" class="main-nav__dropdown-link"><i class="ri-exchange-line"></i> {{ $esa['domain_transfer'] }}</a>
                    </div>
                </div>
                <div class="main-nav__item">
                    <span class="main-nav__link">{{ $esa['hosting'] }} <i class="ri-arrow-down-s-line"></i></span>
                    <div class="main-nav__dropdown">
                        <a href="{{ url('/client/store') }}" class="main-nav__dropdown-link"><i class="ri-server-line"></i> {{ $esa['shared'] }}</a>
                        <a href="{{ url('/client/store') }}" class="main-nav__dropdown-link"><i class="ri-group-line"></i> {{ $esa['reseller'] }}</a>
                        <a href="{{ url('/client/store') }}" class="main-nav__dropdown-link"><i class="ri-mail-check-line"></i> {{ $esa['pec'] }}</a>
                        <a href="{{ route('pages.mail-setup') }}" class="main-nav__dropdown-link"><i class="ri-mail-settings-line"></i> {{ $esa['mail_setup'] }}</a>
                    </div>
                </div>
                @if($sellsVps)
                <div class="main-nav__item">
                    <span class="main-nav__link">{{ $esa['servers'] }} <i class="ri-arrow-down-s-line"></i></span>
                    <div class="main-nav__dropdown">
                        <a href="{{ url('/client/store') }}" class="main-nav__dropdown-link"><i class="ri-cloud-line"></i> {{ $esa['vps'] }}</a>
                    </div>
                </div>
                @endif
                @if(kb_enabled())
                <div class="main-nav__item">
                    <a href="{{ url('/client/knowledgebase') }}" class="main-nav__link">{{ $esa['guides'] }}</a>
                </div>
                @endif
                <div class="main-nav__item">
                    <a href="{{ url('/client/store') }}" class="main-nav__link">{{ $esa['store'] }}</a>
                </div>
            </div>

            <div class="main-nav__right">
                @if($darkModeEnabled ?? false)
                <button onclick="toggleDarkMode()" class="dark-toggle" title="{{ __('client.toggle_dark_mode') }}" style="background:none;border:none;color:rgba(255,255,255,0.7);font-size:18px;cursor:pointer;padding:8px;transition:color 0.2s;">
                    <i class="ri-sun-line" id="lightIcon" style="display:none;"></i>
                    <i class="ri-moon-line" id="darkIcon"></i>
                </button>
                @endif
                <a href="{{ url('/client/cart') }}" class="main-nav__cart"><i class="ri-shopping-cart-2-line"></i></a>
                <a href="{{ route('client.login') }}" class="main-nav__login">{{ __('common.actions.login') }}</a>
                <button class="main-nav__hamburger" @click="mobileMenu = !mobileMenu"><i class="ri-menu-line"></i></button>
            </div>
        </div>
    </div>

    <div class="main-nav__mobile-menu" x-show="mobileMenu" x-transition @click.away="mobileMenu = false" style="display: none;">
        <a href="{{ url('/client/domain-search') }}">{{ $esa['domain_register'] }}</a>
        <a href="{{ url('/client/store') }}">{{ $esa['shared'] }}</a>
        <a href="{{ url('/client/store') }}">{{ $esa['pec'] }}</a>
        @if($sellsVps)
        <a href="{{ url('/client/store') }}">{{ $esa['vps'] }}</a>
        @endif
        <a href="{{ route('pages.mail-setup') }}">{{ $esa['mail_setup'] }}</a>
        @if(kb_enabled())
        <a href="{{ url('/client/knowledgebase') }}">{{ $esa['guides'] }}</a>
        @endif
        <a href="{{ url('/client/contact') }}">{{ $esa['contact'] }}</a>
        <a href="{{ route('client.login') }}">{{ __('common.actions.login') }}</a>
        <a href="{{ route('client.register') }}">{{ __('sections.nav.sign_up') }}</a>
    </div>
</nav>
