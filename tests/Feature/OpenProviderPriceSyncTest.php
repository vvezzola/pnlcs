<?php

use App\Models\Currency;
use App\Models\DomainPricing;
use App\Models\RegistrarSettings;
use Illuminate\Support\Facades\Http;
use Modules\Registrars\OpenProvider\OpenProviderRegistrar;

/*
 * The account's purchase prices come from Openprovider's TLD list (the
 * "reseller" figure, in the account currency) and are written with the
 * operator's margin onto the TLDs whose auto registrar is Openprovider.
 */

function opPriceSettings(array $settings = []): void
{
    foreach (['username' => 'reseller', 'password' => 'secret', 'test_mode' => '1'] + $settings as $k => $v) {
        RegistrarSettings::updateOrCreate(['registrar' => 'openprovider', 'setting' => $k], ['value' => $v]);
    }
}

function opQuote(float $price, string $currency = 'EUR'): array
{
    return ['product' => ['price' => $price, 'currency' => 'USD'], 'reseller' => ['price' => $price, 'currency' => $currency]];
}

function opTld(string $extension, string $registrar = 'openprovider'): DomainPricing
{
    return DomainPricing::create([
        'extension' => $extension, 'auto_registrar' => $registrar,
        'register_price' => 1, 'renew_price' => 1, 'transfer_price' => 1, 'restore_price' => 1,
    ]);
}

function opFakeTlds(array $results): void
{
    Http::fake([
        '*/auth/login' => Http::response(['code' => 0, 'desc' => '', 'data' => ['token' => 'tok']]),
        '*/tlds*' => Http::response(['code' => 0, 'desc' => '', 'data' => ['results' => $results, 'total' => count($results)]]),
    ]);
}

beforeEach(function () {
    Currency::query()->update(['is_default' => false]);
    Currency::updateOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'prefix' => '€', 'suffix' => '', 'rate' => 1.0, 'is_default' => true]);
});

test('the margin is applied and the prices rounded up to a whole euro', function () {
    opPriceSettings(['margin_percent' => '50', 'round_up' => '1']);
    $it = opTld('.it');
    opFakeTlds([['name' => 'it', 'prices' => [
        'create_price' => opQuote(4.99), 'renew_price' => opQuote(3.30),
        'transfer_price' => opQuote(4.00), 'restore_price' => opQuote(10.00),
    ]]]);

    $result = (new OpenProviderRegistrar)->syncPrices();

    expect($result)->toMatchArray(['success' => true, 'updated' => 1])
        ->and((float) $it->fresh()->register_price)->toBe(8.0)
        ->and((float) $it->fresh()->renew_price)->toBe(5.0)
        ->and((float) $it->fresh()->transfer_price)->toBe(6.0)
        ->and((float) $it->fresh()->restore_price)->toBe(15.0);

    // Asked for its own extensions only, as a repeated parameter.
    Http::assertSent(fn ($request) => str_contains($request->url(), '/tlds?')
        && str_contains($request->url(), 'with_price=true')
        && str_contains($request->url(), 'extensions=it'));
});

test('without rounding the margin keeps the cents', function () {
    opPriceSettings(['margin_percent' => '10']);
    $com = opTld('.com');
    opFakeTlds([['name' => 'com', 'prices' => ['create_price' => opQuote(11.60), 'renew_price' => opQuote(10.65)]]]);

    (new OpenProviderRegistrar)->syncPrices();

    expect((float) $com->fresh()->register_price)->toBe(12.76)
        ->and((float) $com->fresh()->renew_price)->toBe(11.72)
        ->and((float) $com->fresh()->transfer_price)->toBe(1.0);
});

test('a price in another currency is converted before the margin', function () {
    Currency::updateOrCreate(['code' => 'USD'], ['name' => 'Dollar', 'prefix' => '$', 'suffix' => '', 'rate' => 1.25, 'is_default' => false]);
    opPriceSettings(['margin_percent' => '0']);
    $com = opTld('.com');
    opFakeTlds([['name' => 'com', 'prices' => ['create_price' => opQuote(10.00, 'USD')]]]);

    (new OpenProviderRegistrar)->syncPrices();

    expect((float) $com->fresh()->register_price)->toBe(8.0);
});

test('an extension Openprovider does not price, or another registrar\'s, keeps its prices', function () {
    opPriceSettings(['margin_percent' => '50']);
    $missing = opTld('.xyz');
    $other = opTld('.de', 'internetbs');
    opFakeTlds([['name' => 'xyz', 'prices' => ['create_price' => opQuote(0)]]]);

    $result = (new OpenProviderRegistrar)->syncPrices();

    expect($result)->toMatchArray(['success' => true, 'updated' => 0, 'skipped' => 1])
        ->and((float) $missing->fresh()->register_price)->toBe(1.0)
        ->and((float) $other->fresh()->register_price)->toBe(1.0);
});

test('the daily command syncs only a registrar switched to daily', function () {
    opPriceSettings(['margin_percent' => '0']);
    $it = opTld('.it');
    opFakeTlds([['name' => 'it', 'prices' => ['create_price' => opQuote(4.99)]]]);

    $this->artisan('pnlcs:sync-domain-prices')->assertSuccessful();
    expect((float) $it->fresh()->register_price)->toBe(1.0);

    opPriceSettings(['daily_price_sync' => '1']);
    $this->artisan('pnlcs:sync-domain-prices')
        ->expectsOutputToContain('OpenProvider: 1 updated')
        ->assertSuccessful();
    expect((float) $it->fresh()->register_price)->toBe(4.99);
});
