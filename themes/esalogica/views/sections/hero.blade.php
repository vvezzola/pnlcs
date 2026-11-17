{{-- ===== HERO (Esalogica) =====
     The wording is in themes/esalogica/strings.php. The title may carry a
     <span> for the accent colour; it goes through inline_markup() like the
     original section. --}}
@php
    $esa = (require base_path('themes/esalogica/strings.php'))[app()->getLocale() === 'it' ? 'it' : 'en']['hero'];
@endphp
<section class="hero">
    <div class="hero__orb hero__orb--1"></div>
    <div class="hero__orb hero__orb--2"></div>
    <div class="hero__orb hero__orb--3"></div>
    <div class="container">
        <div class="hero__inner">
            <div>
                <div class="hero__badge"><i class="ri-shield-check-line"></i> {{ $esa['badge'] }}</div>
                <h1 class="hero__title">{{ inline_markup($esa['title']) }}</h1>
                <p class="hero__title-sub">{{ $esa['subtitle'] }}</p>
                <p class="hero__desc">{{ $esa['description'] }}</p>
                <div class="hero__stats">
                    <div class="hero__stat"><i class="ri-dashboard-line"></i> {{ $esa['stat_1'] }}</div>
                    <div class="hero__stat"><i class="ri-lock-line"></i> {{ $esa['stat_2'] }}</div>
                </div>
                <a href="{{ url('/client/store') }}" class="btn-accent" style="font-size: 16px; padding: 16px 36px;">
                    {{ $esa['cta'] }} <i class="ri-arrow-right-line"></i>
                </a>
            </div>
            <div class="hero__visual">
                <canvas id="heroCanvas" style="width:100%;max-width:480px;height:400px;"></canvas>
                <div class="hero__callout hero__callout--1">
                    <i class="ri-gift-line"></i> {{ $esa['callout_1'] }}
                </div>
                <div class="hero__callout hero__callout--2">
                    <i class="ri-shield-check-line"></i> {{ $esa['callout_2'] }}
                </div>
            </div>
        </div>
    </div>
</section>
