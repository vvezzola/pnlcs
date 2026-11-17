{{-- ===== VPS PLANS (Esalogica) =====
     Drawn only when VPS products are on sale: the original section showed a
     "Cloud VPS on Panelica infrastructure" panel even with none. --}}
@php
    $esa = (require base_path('themes/esalogica/strings.php'))[app()->getLocale() === 'it' ? 'it' : 'en']['vps'];
    $perMonth = (require base_path('themes/esalogica/strings.php'))[app()->getLocale() === 'it' ? 'it' : 'en']['hosting']['per_month'];
    $vpsProducts = isset($products) ? $products->filter(fn ($p) => $p->type === 'vps' || ($p->group && str_contains(strtolower($p->group->name), 'vps'))) : collect();
@endphp
@if($vpsProducts->isNotEmpty())
<section class="vps">
    <div class="container">
        <h2 class="section-title">{{ $esa['title'] }}</h2>
        <p class="section-subtitle">{{ $esa['subtitle'] }}</p>
        <div class="vps__layout">
            <div class="vps__visual">
                <div class="vps__server-rack">
                    <div class="vps__rack-unit"><span class="vps__rack-led vps__rack-led--on"></span><span class="vps__rack-slots"></span></div>
                    <div class="vps__rack-unit"><span class="vps__rack-led vps__rack-led--on"></span><span class="vps__rack-slots"></span></div>
                    <div class="vps__rack-unit"><span class="vps__rack-led vps__rack-led--blink"></span><span class="vps__rack-slots"></span></div>
                    <div class="vps__rack-unit"><span class="vps__rack-led vps__rack-led--on"></span><span class="vps__rack-slots"></span></div>
                </div>
                <h3>{{ $esa['visual_title'] }}</h3>
                <p>{{ $esa['visual_desc'] }}</p>
            </div>
            <div class="vps__grid">
                @foreach($vpsProducts->take(4)->values() as $product)
                @php
                    $priced = $product->pricedCycles($currency?->id ?? null);
                    $price = $priced['monthly'] ?? null;
                    $configOptions = is_string($product->config_options) ? json_decode($product->config_options, true) : ($product->config_options ?? []);
                    $specs = [];
                    for ($i = 1; $i <= 5; $i++) {
                        if (!empty($configOptions["f{$i}"])) {
                            $specs[] = $configOptions["f{$i}"];
                        }
                    }
                    if (! $specs) {
                        $specs = array_map(fn ($r) => $r['text'], $product->resourceSummary());
                    }
                @endphp
                <div class="vps-card">
                    <div class="vps-card__name">{{ $product->name }}</div>
                    <div class="vps-card__specs">
                        @foreach($specs as $spec)
                        <div class="vps-card__spec"><i class="ri-check-line"></i> {{ $spec }}</div>
                        @endforeach
                    </div>
                    @if($price !== null)
                        <div class="vps-card__price">{{ display_money_fmt($price) }}<small>{{ $perMonth }}</small></div>
                    @endif
                    <a href="{{ url('/client/store/configure') }}/{{ $product->slug }}" class="vps-card__btn">{{ $esa['configure'] }} <i class="ri-arrow-right-line"></i></a>
                </div>
                @endforeach
            </div>
        </div>
    </div>
</section>
@endif
