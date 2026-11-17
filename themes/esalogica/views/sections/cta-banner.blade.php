{{-- ===== CALL TO ACTION (Esalogica) ===== --}}
@php
    $esa = (require base_path('themes/esalogica/strings.php'))[app()->getLocale() === 'it' ? 'it' : 'en']['cta'];
@endphp
<section class="cta-banner">
    <div class="container">
        <div class="cta-banner__inner">
            <h2 class="cta-banner__title">{{ $esa['title'] }}</h2>
            <p class="cta-banner__desc">{{ $esa['subtitle'] }}</p>
            <a href="{{ url('/client/contact') }}" class="btn-accent" style="font-size: 16px; padding: 16px 36px;">
                {{ $esa['button'] }} <i class="ri-arrow-right-line"></i>
            </a>
            <p class="cta-banner__note">{{ $esa['note'] }}</p>
        </div>
    </div>
</section>
