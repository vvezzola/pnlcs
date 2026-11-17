{{-- ===== WHAT EVERY PLAN INCLUDES (Esalogica) ===== --}}
@php
    $esa = (require base_path('themes/esalogica/strings.php'))[app()->getLocale() === 'it' ? 'it' : 'en']['infra'];
@endphp
<section class="infra">
    <div class="container">
        <h2 class="section-title">{{ $esa['title'] }}</h2>
        <p class="section-subtitle">{{ $esa['subtitle'] }}</p>
        <div class="infra__grid">
            @foreach($esa['cards'] as $card)
            <div class="infra-card">
                <div class="infra-card__icon"><i class="{{ $card['icon'] }}"></i></div>
                <div class="infra-card__title">{{ $card['title'] }}</div>
                <div class="infra-card__desc">{{ $card['desc'] }}</div>
            </div>
            @endforeach
        </div>
    </div>
</section>
