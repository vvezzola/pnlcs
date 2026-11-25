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
    $plans['reseller_addon'] = [];
    foreach (['EsaBackup25' => 21, 'EsaBackup100' => 22, 'EsaBackup500' => 23, 'EsaBackup1T' => 24] as $label => $id) {
        $plans['client'][] = ['id' => $id, 'label' => $label, 'type' => 'client', 'sells_license' => false, 'services' => $wfp];
    }
    foreach (['EsaBackup Reseller 3 TB' => 25, 'EsaBackup Reseller 10 TB' => 26] as $label => $id) {
        $plans['reseller'][] = ['id' => $id, 'label' => $label, 'type' => 'reseller', 'sells_license' => false, 'services' => $wfp];
    }
    foreach (['EsaBackup Spazio +100 GB', 'EsaBackup Spazio +300 GB', 'EsaBackup Spazio +500 GB', 'EsaBackup Account +10', 'EsaBackup Cartelle +5', 'EsaBackup Traffico +1 TB', 'EsaBackup Traffico +5 TB', 'EsaBackup Copia remota'] as $i => $label) {
        $plans['client_addon'][] = ['id' => 30 + $i, 'label' => $label, 'type' => 'client_addon', 'services' => $wfp];
    }
    $plans['reseller_addon'][] = ['id' => 40, 'label' => 'EsaBackup Reseller Copia remota', 'type' => 'reseller_addon', 'services' => $wfp];
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
        ->expectsOutputToContain('+ prodotto EsaBackup1T sul piano #24, 45.00/450.00 (nascosto)')
        ->expectsOutputToContain('Prova: nulla è stato scritto')
        ->assertSuccessful();

    expect(Product::where('slug', 'like', 'esabackup-%')->count())->toBe(0);
});

it('makes the products on their plans, hidden, with the add-ons as priced options, and a second run changes nothing', function () {
    ebServer();

    $this->artisan('esalogica:esabackup-products', ['--write' => true])->assertSuccessful();

    $group = ProductGroup::where('slug', 'esabackup')->firstOrFail();
    $products = Product::where('group_id', $group->id)->orderBy('sort_order')->get()->keyBy('slug');
    expect($products->pluck('name')->all())->toBe(['EsaBackup25', 'EsaBackup100', 'EsaBackup500', 'EsaBackup1T', 'EsaBackup Reseller 3 TB', 'EsaBackup Reseller 10 TB'])
        ->and($products->every(fn (Product $product) => $product->hidden && $product->server_type === EsapanelModule::NAME))->toBeTrue()
        ->and($products->pluck('config_options.package_name')->all())->toBe(['21', '22', '23', '24', '25', '26']);

    // Yearly only up to 100 GB.
    expect(ebPrice('product', $products['esabackup-100']->id))->toBe([-1.0, 60.0])
        ->and(ebPrice('product', $products['esabackup-1t']->id))->toBe([45.0, 450.0])
        ->and(ebPrice('product', $products['esabackup-reseller-10tb']->id))->toBe([260.0, 2600.0]);

    // Each product its own add-ons, the remote copy on all of them: no extra
    // space on 100 GB, no extra accounts on 1 TB.
    $options = fn (string $slug) => ConfigOption::whereIn('group_id', $products[$slug]->configOptionGroups()->pluck('config_option_groups.id'))
        ->orderBy('sort_order')->pluck('option_name')->all();
    expect($options('esabackup-25'))->toBe(['addon|Copia remota'])
        ->and($options('esabackup-reseller-3tb'))->toBe(['addon|Copia remota'])
        ->and($options('esabackup-100'))->toBe(['addon|Account aggiuntivi', 'addon|Cartelle condivise aggiuntive', 'addon|Traffico aggiuntivo', 'addon|Copia remota'])
        ->and($options('esabackup-1t'))->toBe(['addon|Spazio aggiuntivo', 'addon|Cartelle condivise aggiuntive', 'addon|Traffico aggiuntivo', 'addon|Copia remota']);

    $choices = fn (string $slug, string $option) => ConfigOptionSub::whereIn('config_id', ConfigOption::whereIn('group_id', $products[$slug]->configOptionGroups()->pluck('config_option_groups.id'))
        ->where('option_name', $option)->pluck('id'))->orderBy('sort_order')->get();
    $price = fn (string $slug, string $option, string $addon) => ebPrice(ConfigOptionSub::PRICING_TYPE, $choices($slug, $option)->first(fn ($sub) => str_starts_with($sub->option_name, $addon.'|'))->id);

    // The remote copy: 70% of the plan; a reseller's is a reseller add-on.
    expect($price('esabackup-500', 'addon|Copia remota', 'EsaBackup Copia remota'))->toBe([16.8, 168.0])
        ->and($price('esabackup-100', 'addon|Copia remota', 'EsaBackup Copia remota'))->toBe([-1.0, 42.0])
        ->and($price('esabackup-reseller-10tb', 'addon|Copia remota', 'EsaBackup Reseller Copia remota'))->toBe([182.0, 1820.0]);

    // No +500 GB on 500 GB: it would make 1 TB for less than the 1 TB plan.
    expect($choices('esabackup-500', 'addon|Spazio aggiuntivo')->pluck('option_name')->all())->toBe(['none|Nessuno', 'EsaBackup Spazio +100 GB|+100 GB', 'EsaBackup Spazio +300 GB|+300 GB'])
        ->and($choices('esabackup-1t', 'addon|Spazio aggiuntivo')->pluck('option_name'))->toContain('EsaBackup Spazio +500 GB|+500 GB')
        ->and($price('esabackup-500', 'addon|Spazio aggiuntivo', 'EsaBackup Spazio +300 GB'))->toBe([6.0, 60.0])
        ->and($price('esabackup-100', 'addon|Traffico aggiuntivo', 'EsaBackup Traffico +1 TB'))->toBe([-1.0, 50.0]);

    $this->artisan('esalogica:esabackup-products', ['--write' => true])
        ->expectsOutputToContain('Niente da cambiare.')
        ->assertSuccessful();
});

it('stops before writing when a plan or an add-on is missing on the server', function () {
    ebServer(['EsaBackup500', 'EsaBackup Reseller Copia remota']);

    $this->artisan('esalogica:esabackup-products', ['--write' => true])
        ->expectsOutputToContain('Sul server Esapanel mancano: EsaBackup500, EsaBackup Reseller Copia remota')
        ->assertFailed();

    expect(Product::where('slug', 'like', 'esabackup-%')->count())->toBe(0)
        ->and(ProductGroup::where('slug', 'esabackup')->exists())->toBeFalse();
});
