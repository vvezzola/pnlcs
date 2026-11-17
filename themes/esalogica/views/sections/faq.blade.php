{{-- ===== FAQ (Esalogica) ===== --}}
@php
    $esa = (require base_path('themes/esalogica/strings.php'))[app()->getLocale() === 'it' ? 'it' : 'en']['faq'];
@endphp
<section class="faq">
    <div class="container">
        <h2 class="section-title">{{ $esa['title'] }}</h2>
        <p class="section-subtitle">{{ $esa['subtitle'] }}</p>
        <div class="faq__list">
            @foreach($esa['items'] as $idx => $item)
            <div class="faq__item" x-data="{ open: {{ $idx === 0 ? 'true' : 'false' }} }">
                <button class="faq__question" @click="open = !open">
                    <span>{{ $item['question'] }}</span>
                    <i class="ri-arrow-down-s-line" :style="open && 'transform: rotate(180deg)'"></i>
                </button>
                <div class="faq__answer" x-show="open" x-collapse>
                    {{ $item['answer'] }}
                </div>
            </div>
            @endforeach
        </div>
    </div>
</section>
