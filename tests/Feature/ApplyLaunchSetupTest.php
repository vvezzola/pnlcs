<?php

use App\Models\Currency;
use App\Models\DomainPricing;
use App\Models\Pricing;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\RegistrarSettings;
use App\Models\Setting;
use App\Models\SslModuleSettings;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;

/*
 * Esalogica only: the launch settings and the 2027 hosting prices, shown as
 * a dry run and written only with --write.
 */

beforeEach(function () {
    Currency::query()->update(['is_default' => false]);
    Currency::updateOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'prefix' => '€', 'suffix' => '', 'rate' => 1.0, 'is_default' => true]);
    foreach (['username' => 'reseller', 'password' => 'secret', 'test_mode' => '0'] as $k => $v) {
        RegistrarSettings::updateOrCreate(['registrar' => 'openprovider', 'setting' => $k], ['value' => $v]);
    }
    // Openprovider prices .it and .com only.
    $quote = fn (float $price) => ['product' => ['price' => $price, 'currency' => 'EUR'], 'reseller' => ['price' => $price, 'currency' => 'EUR']];
    Http::fake([
        '*/auth/login' => Http::response(['code' => 0, 'desc' => '', 'data' => ['token' => 'tok']]),
        '*/tlds*' => Http::response(['code' => 0, 'desc' => '', 'data' => ['results' => [
            ['name' => 'it', 'prices' => ['max_period' => 1, 'create_price' => $quote(4.99), 'renew_price' => $quote(3.30), 'transfer_price' => $quote(4.00)]],
            ['name' => 'com', 'prices' => ['create_price' => $quote(11.60), 'renew_price' => $quote(10.65), 'transfer_price' => $quote(10.65)]],
        ]]]),
    ]);
});

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

test('the domains go to Openprovider with the margin and only priced ones go on sale', function () {
    launchCatalog();
    $demo = DomainPricing::create(['extension' => '.io', 'register_price' => 39.99, 'renew_price' => 44.99, 'enabled' => true]);
    $com = DomainPricing::create(['extension' => '.com', 'register_price' => 10.99, 'renew_price' => 12.99, 'transfer_price' => 10.99, 'enabled' => true]);

    $this->artisan('esalogica:apply-launch-setup')
        ->expectsOutputToContain('+ .it: nuova, Openprovider')
        ->expectsOutputToContain('non più in vendita: .io')
        ->assertSuccessful();
    expect(DomainPricing::where('extension', '.it')->exists())->toBeFalse();

    $this->artisan('esalogica:apply-launch-setup --write')
        ->expectsOutputToContain('senza prezzo, restano fuori vendita')
        ->assertSuccessful();

    $it = DomainPricing::where('extension', '.it')->first();
    expect($it->enabled)->toBeTrue()
        ->and($it->auto_registrar)->toBe('openprovider')
        ->and((float) $it->register_price)->toBe(8.0)
        ->and((float) $it->renew_price)->toBe(5.0)
        ->and($it->max_years)->toBe(1)
        ->and((float) $com->fresh()->renew_price)->toBe(16.0)
        ->and($com->fresh()->enabled)->toBeTrue()
        ->and($demo->fresh()->enabled)->toBeFalse()
        ->and(DomainPricing::where('extension', '.de')->first()->enabled)->toBeFalse()
        ->and(RegistrarSettings::where('registrar', 'openprovider')->where('setting', 'margin_percent')->value('value'))->toBe('50');
});

test('nothing goes on sale when Openprovider cannot be reached', function () {
    launchCatalog();
    // A fresh fake: the stubs of beforeEach would otherwise answer first.
    Http::swap(new HttpFactory);
    Http::fake(['*' => Http::response(['code' => 1, 'desc' => 'Authentication failed'], 401)]);

    $this->artisan('esalogica:apply-launch-setup --write')
        ->expectsOutputToContain('Prezzi domini non sincronizzati')
        ->assertSuccessful();

    expect(DomainPricing::whereIn('extension', ['.it', '.com'])->where('enabled', true)->exists())->toBeFalse();
});

test('the SSL products are created once, sold yearly and mapped to their SSL2BUY certificate', function () {
    launchCatalog();
    SslModuleSettings::setSetting('ssl2buy', 'product_map', '99=202');

    $this->artisan('esalogica:apply-launch-setup')
        ->expectsOutputToContain('+ Certificato SSL Wildcard AlphaSSL: 120.00 €/anno, SSL2BUY 102')
        ->expectsOutputToContain('SSL2BUY senza credenziali')
        ->assertSuccessful();
    expect(Product::where('type', 'ssl')->exists())->toBeFalse();

    $this->artisan('esalogica:apply-launch-setup --write')->assertSuccessful();

    $wildcard = Product::where('slug', 'ssl-alphassl-wildcard')->first();
    $pricing = Pricing::where('rel_id', $wildcard->id)->where('type', 'product')->first();
    $positive = Product::where('slug', 'ssl-positivessl')->first();
    expect($wildcard->type)->toBe('ssl')
        ->and($wildcard->ssl_module)->toBe('ssl2buy')
        ->and($wildcard->show_domain_options)->toBeTrue()
        ->and($wildcard->group->slug)->toBe('certificati-ssl')
        ->and((float) $pricing->annually)->toBe(120.0)
        ->and((float) $pricing->monthly)->toBe(-1.0)
        ->and(SslModuleSettings::getSetting('ssl2buy', 'product_map'))->toBe(collect([
            $positive->id => 351, Product::where('slug', 'ssl-alphassl')->value('id') => 101, $wildcard->id => 102, 99 => 202,
        ])->sortKeys()->map(fn ($code, $id) => "{$id}={$code}")->implode(', '));

    $this->artisan('esalogica:apply-launch-setup')
        ->doesntExpectOutputToContain('Certificato SSL')
        ->assertSuccessful();
    expect(Product::where('type', 'ssl')->count())->toBe(3)
        ->and(ProductGroup::where('slug', 'certificati-ssl')->count())->toBe(1);
});

test('the SSL products go into the group the operator made', function () {
    launchCatalog();
    $group = ProductGroup::factory()->create(['name' => 'SSL', 'slug' => 'ssl']);

    $this->artisan('esalogica:apply-launch-setup --write')
        ->expectsOutputToContain('= gruppo: SSL')
        ->assertSuccessful();

    expect(Product::where('type', 'ssl')->pluck('group_id')->unique()->all())->toBe([$group->id])
        ->and(ProductGroup::where('slug', 'certificati-ssl')->exists())->toBeFalse();
});
