<?php

use App\Models\Currency;
use App\Models\Pricing;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Services\BillingCycleHelper;

/*
 * The store wrote a product's starting price with the cycle's key after it,
 * "€72.00 EUR /annually", in every language. It now says the period in the
 * visitor's language: "/anno", "/year".
 */

function storeProductPricedYearly(): Product
{
    $currency = Currency::where('is_default', true)->first()
        ?? Currency::factory()->create(['is_default' => true, 'rate' => 1]);
    $product = Product::factory()->create([
        'group_id' => ProductGroup::factory()->create(['hidden' => false])->id,
        'hidden' => false, 'retired' => false, 'pay_type' => 'recurring',
    ]);
    Pricing::updateOrCreate(
        ['type' => 'product', 'rel_id' => $product->id, 'currency_id' => $currency->id],
        ['monthly' => -1, 'quarterly' => -1, 'semiannually' => -1, 'annually' => 72, 'biennially' => -1, 'triennially' => -1],
    );

    return $product;
}

test('the store says the period of the price in the visitor\'s language', function () {
    storeProductPricedYearly();

    $this->get(route('client.store').'?lang=it')->assertOk()->assertSee('/anno')->assertDontSee('/annually');
    $this->get(route('client.store').'?lang=en')->assertOk()->assertSee('/year')->assertDontSee('/annually');
});

test('a term of months and of years reads as its length', function (string $cycle, string $label) {
    app()->setLocale('it');

    expect(BillingCycleHelper::perLabel($cycle))->toBe($label);
})->with([
    ['monthly', '/mese'],
    ['semiannually', '/semestre'],
    ['biennially', '/2 anni'],
    ['7months', '/7 mesi'],
]);
