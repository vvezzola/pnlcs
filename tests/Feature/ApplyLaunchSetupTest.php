<?php

use App\Models\Currency;
use App\Models\Pricing;
use App\Models\Product;
use App\Models\Setting;

/*
 * Esalogica only: the launch settings and the 2027 hosting prices, shown as
 * a dry run and written only with --write.
 */

function launchCatalog(): array
{
    $euro = Currency::firstOrCreate(['code' => 'EUR'], Currency::factory()->raw(['code' => 'EUR']));
    $pro = Product::factory()->create(['slug' => 'business20-copy']);
    Pricing::factory()->create(['rel_id' => $pro->id, 'currency_id' => $euro->id, 'monthly' => 21, 'annually' => 210, 'quarterly' => 60]);

    return [$euro, $pro];
}

test('the dry run lists the changes and writes nothing', function () {
    [, $pro] = launchCatalog();
    Setting::set('KnowledgeBaseEnabled', '1');

    $this->artisan('esalogica:apply-launch-setup')
        ->expectsOutputToContain("KnowledgeBaseEnabled: '1' -> '0'")
        ->expectsOutputToContain('slug: business20-copy -> pro50')
        ->expectsOutputToContain('monthly: 21.00 -> 20.00')
        ->expectsOutputToContain('Aggiungi --write')
        ->assertSuccessful();

    expect(Setting::get('KnowledgeBaseEnabled'))->toBe('1')
        ->and($pro->fresh()->slug)->toBe('business20-copy');
});

test('--write applies the settings, the slug and the price list once', function () {
    [$euro, $pro] = launchCatalog();

    $this->artisan('esalogica:apply-launch-setup --write')->assertSuccessful();

    $pricing = Pricing::where('rel_id', $pro->id)->where('currency_id', $euro->id)->first();
    expect($pro->fresh()->slug)->toBe('pro50')
        ->and((float) $pricing->monthly)->toBe(20.0)
        ->and((float) $pricing->annually)->toBe(240.0)
        ->and((float) $pricing->quarterly)->toBe(-1.0)
        ->and(Setting::get('CheckoutClauseApproval'))->toBe('1')
        ->and(Setting::get('TOSUrl'))->toBe('https://my.esalogica.com/legal/terms');

    $this->artisan('esalogica:apply-launch-setup')
        ->expectsOutputToContain('= prezzi già a listino')
        ->doesntExpectOutputToContain('slug:')
        ->assertSuccessful();
});

test('a slug already taken by another product is left alone', function () {
    [, $pro] = launchCatalog();
    Product::factory()->create(['slug' => 'pro50']);

    $this->artisan('esalogica:apply-launch-setup --write')
        ->expectsOutputToContain('già usato da un altro prodotto')
        ->assertSuccessful();

    expect($pro->fresh()->slug)->toBe('business20-copy');
});
