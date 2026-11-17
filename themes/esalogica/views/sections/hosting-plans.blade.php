{{-- ===== HOSTING PLANS (Esalogica) =====
     The plans, prices and limits come from the products, as in the original
     section. Each card shows the monthly price and, when the plan is sold
     yearly too, the yearly one: the domain is included only with yearly
     billing. --}}
@php
    $esa = (require base_path('themes/esalogica/strings.php'))[app()->getLocale() === 'it' ? 'it' : 'en']['hosting'];
    $hostingProducts = isset($products) ? $products->filter(fn ($p) => $p->type === 'hosting') : collect();
    $icons = ['ri-rocket-line', 'ri-speed-line', 'ri-flashlight-line', 'ri-vip-crown-line'];
@endphp
@if($hostingProducts->isNotEmpty())
<section class="hosting-plans">
    <div class="container">
        <h2 class="section-title">{{ $esa['title'] }}</h2>
        <p class="section-subtitle">{{ $esa['subtitle'] }}</p>
        <div class="hosting-plans__grid">
            <div class="hosting-plans__promo">
                <div class="hosting-plans__promo-icon"><i class="ri-global-line"></i></div>
                <h3>{{ $esa['promo_title'] }}</h3>
                <p>{{ $esa['promo_text'] }}</p>
                <a href="{{ url('/client/store') }}" class="hosting-plans__promo-btn">{{ $esa['promo_cta'] }} <i class="ri-arrow-right-line"></i></a>
            </div>
            @foreach($hostingProducts->take(3)->values() as $idx => $product)
            @php
                $priced = $product->pricedCycles($currency?->id ?? null);
                $monthlyPrice = $priced['monthly'] ?? null;
                $annualPrice = $priced['annually'] ?? null;
                $configOptions = is_string($product->config_options) ? json_decode($product->config_options, true) : ($product->config_options ?? []);
                $features = [];
                for ($i = 1; $i <= 7; $i++) {
                    if (!empty($configOptions["f{$i}"])) {
                        $features[] = $configOptions["f{$i}"];
                    }
                }
                if (! $features) {
                    $features = array_map(fn ($r) => $r['text'], $product->resourceSummary());
                }
                $isPopular = $idx === 1;
            @endphp
            <div class="plan-card {{ $isPopular ? 'plan-card--popular' : '' }}">
                @if($isPopular)
                <div class="plan-card__badge">{{ $esa['most_popular'] }}</div>
                @endif
                <div class="plan-card__icon"><i class="{{ $icons[$idx] ?? 'ri-server-line' }}"></i></div>
                <div class="plan-card__name">{{ $product->name }}</div>
                <div class="plan-card__subtitle">{{ Str::limit($product->descriptionText(), 160) }}</div>
                <div class="plan-card__pricing">
                    @if($monthlyPrice !== null)
                        <div class="plan-card__price">{{ display_money_fmt($monthlyPrice) }}<small>{{ $esa['per_month'] }}</small></div>
                        @if($annualPrice !== null)
                        <div class="plan-card__subtitle" style="margin-top:4px">{{ str_replace(':price', display_money_fmt($annualPrice), $esa['or_yearly']) }}</div>
                        @endif
                    @elseif($annualPrice !== null)
                        <div class="plan-card__price">{{ display_money_fmt($annualPrice) }}<small>{{ $esa['per_year'] }}</small></div>
                    @else
                        <div class="plan-card__price">{{ $esa['contact_us'] }}</div>
                    @endif
                </div>
                <div class="plan-card__features">
                    @foreach($features as $feature)
                    <div class="plan-card__feature">
                        <i class="ri-check-line"></i>
                        <span>{{ $feature }}</span>
                    </div>
                    @endforeach
                </div>
                <div class="plan-card__cp"><i class="ri-dashboard-line"></i> {{ $esa['control_panel'] }}</div>
                <a href="{{ url('/client/store/configure') }}/{{ $product->slug }}" class="plan-card__btn {{ $isPopular ? 'plan-card__btn--primary' : 'plan-card__btn--outline' }}">
                    {{ $esa['order'] }} <i class="ri-arrow-right-line"></i>
                </a>
            </div>
            @endforeach
        </div>
    </div>
</section>
@endif
