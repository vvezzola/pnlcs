<?php

use App\Models\ConfigOption;
use App\Models\ConfigOptionSub;
use App\Models\Currency;
use App\Models\Pricing;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\Server;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Modules\Servers\Esapanel\EsapanelModule;

/*
 * Esalogica only: the EsaBackup products of the 2027 price list on the
 * EsaBackup plans of an Esapanel server, the add-ons as priced options.
 */

/** An Esapanel server with the EsaBackup plans and add-ons, less the ones named. */
function ebServer(array $without = []): Server
{
    Currency::query()->update(['is_default' => false]);
    Currency::updateOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'prefix' => '€', 'suffix' => '', 'rate' => 1, 'is_default' => true]);

    $wfp = [['code' => 'wfp.storage']];
    $plans = ['client' => [], 'reseller' => [], 'client_addon' => []];
    foreach (['EsaBackup100' => 22, 'EsaBackup500' => 23, 'EsaBackup1T' => 24] as $label => $id) {
        $plans['client'][] = ['id' => $id, 'label' => $label, 'type' => 'client', 'sells_license' => false, 'services' => $wfp];
    }
    $plans['reseller'][] = ['id' => 25, 'label' => 'EsaBackup Reseller 10 TB', 'type' => 'reseller', 'sells_license' => false, 'services' => $wfp];
    foreach (['EsaBackup Spazio +100 GB', 'EsaBackup Spazio +200 GB', 'EsaBackup Spazio +500 GB', 'EsaBackup Spazio +1 TB', 'EsaBackup Spazio +2 TB', 'EsaBackup Account +5'] as $i => $label) {
        $plans['client_addon'][] = ['id' => 30 + $i, 'label' => $label, 'type' => 'client_addon', 'services' => $wfp];
    }
    foreach ($plans as $type => $list) {
        $plans[$type] = array_values(array_filter($list, fn ($plan) => ! in_array($plan['label'], $without, true)));
    }

    Http::preventStrayRequests();
    Http::fake(['https://wfp.test:9943/api/v1/*' => function (Request $request) use ($plans) {
        if (str_contains($request->url(), 'server/modules')) {
            return Http::response(['data' => [['code' => 'wfp', 'role' => 'customer', 'active' => true, 'sellable' => true, 'api_actions' => true]]]);
        }

        return Http::response(['data' => $plans[$request->data()['type'] ?? ''] ?? [], 'meta' => ['last_page' => 1]]);
    }]);

    return Server::factory()->create(['type' => EsapanelModule::NAME, 'hostname' => 'wfp.test', 'ip_address' => '', 'port' => null, 'access_hash' => 'key', 'active' => true]);
}

function ebPrice(string $type, int $id): array
{
    $row = Pricing::where('type', $type)->where('rel_id', $id)->first();

    return [(float) $row->monthly, (float) $row->annually];
}

it('shows what it would make without writing anything', function () {
    ebServer();

    $this->artisan('esalogica:esabackup-products')
        ->expectsOutputToContain('+ prodotto EsaBackup1T sul piano #24, 29.00/290.00 (nascosto)')
        ->expectsOutputToContain('Prova: nulla è stato scritto')
        ->assertSuccessful();

    expect(Product::where('slug', 'like', 'esabackup-%')->count())->toBe(0);
});

it('makes the products on their plans, hidden, with the add-ons as priced options, and a second run changes nothing', function () {
    ebServer();

    $this->artisan('esalogica:esabackup-products', ['--write' => true])->assertSuccessful();

    $group = ProductGroup::where('slug', 'esabackup')->firstOrFail();
    $products = Product::where('group_id', $group->id)->orderBy('sort_order')->get();
    expect($products->pluck('name')->all())->toBe(['EsaBackup100', 'EsaBackup500', 'EsaBackup1T', 'EsaBackup Reseller 10 TB'])
        ->and($products->every(fn (Product $product) => $product->hidden && $product->server_type === EsapanelModule::NAME))->toBeTrue()
        ->and($products->pluck('config_options.package_name')->all())->toBe(['22', '23', '24', '25']);

    $oneTb = $products->firstWhere('slug', 'esabackup-1t');
    expect($products->pluck('pay_type')->unique()->all())->toBe(['recurring'])
        ->and(ebPrice('product', $oneTb->id))->toBe([29.0, 290.0])
        ->and(ebPrice('product', $products->firstWhere('slug', 'esabackup-reseller-10tb')->id))->toBe([180.0, 1800.0]);

    // The add-ons go with the paid customer plans only.
    expect($oneTb->configOptionGroups()->pluck('name')->all())->toBe(['EsaBackup extras'])
        ->and($products->firstWhere('slug', 'esabackup-reseller-10tb')->configOptionGroups()->count())->toBe(0);

    $space = ConfigOption::where('option_name', 'addon|Spazio aggiuntivo')->firstOrFail();
    $subs = ConfigOptionSub::where('config_id', $space->id)->orderBy('sort_order')->get();
    expect($subs->pluck('option_name')->all())->toBe(['none|Nessuno', 'EsaBackup Spazio +100 GB|+100 GB', 'EsaBackup Spazio +200 GB|+200 GB', 'EsaBackup Spazio +500 GB|+500 GB', 'EsaBackup Spazio +1 TB|+1 TB', 'EsaBackup Spazio +2 TB|+2 TB'])
        ->and(ebPrice(ConfigOptionSub::PRICING_TYPE, $subs[4]->id))->toBe([18.0, 180.0]);

    $this->artisan('esalogica:esabackup-products', ['--write' => true])
        ->expectsOutputToContain('Niente da cambiare.')
        ->assertSuccessful();
});

it('stops before writing when a plan or an add-on is missing on the server', function () {
    ebServer(['EsaBackup500', 'EsaBackup Spazio +2 TB']);

    $this->artisan('esalogica:esabackup-products', ['--write' => true])
        ->expectsOutputToContain('Sul server Esapanel mancano: EsaBackup500, EsaBackup Spazio +2 TB')
        ->assertFailed();

    expect(Product::where('slug', 'like', 'esabackup-%')->count())->toBe(0)
        ->and(ProductGroup::where('slug', 'esabackup')->exists())->toBeFalse();
});
