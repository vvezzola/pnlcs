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
    $id = 20;
    foreach (['EsaBackup25', 'EsaBackup100', 'EsaBackup500', 'EsaBackup1T'] as $label) {
        foreach ([$label, $label.' Copia remota'] as $plan) {
            $plans['client'][] = ['id' => ++$id, 'label' => $plan, 'type' => 'client', 'sells_license' => false, 'services' => $wfp];
        }
    }
    foreach (['EsaBackup Reseller 3 TB', 'EsaBackup Reseller 3 TB Copia remota', 'EsaBackup Reseller 10 TB', 'EsaBackup Reseller 10 TB Copia remota'] as $plan) {
        $plans['reseller'][] = ['id' => ++$id, 'label' => $plan, 'type' => 'reseller', 'sells_license' => false, 'services' => $wfp];
    }
    foreach (['EsaBackup Spazio +100 GB', 'EsaBackup Spazio +300 GB', 'EsaBackup Spazio +500 GB', 'EsaBackup Account +10', 'EsaBackup Cartelle +5', 'EsaBackup Traffico +1 TB', 'EsaBackup Traffico +5 TB'] as $i => $label) {
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
        ->expectsOutputToContain('+ prodotto EsaBackup1T sul piano #27, 45.00/450.00 (nascosto)')
        ->expectsOutputToContain('Prova: nulla è stato scritto')
        ->assertSuccessful();

    expect(Product::where('slug', 'like', 'esabackup-%')->count())->toBe(0);
});

it('makes the products on their plans, hidden, with the add-ons as priced options, and a second run changes nothing', function () {
    ebServer();

    $this->artisan('esalogica:esabackup-products', ['--write' => true])->assertSuccessful();

    $group = ProductGroup::where('slug', 'esabackup')->firstOrFail();
    $products = Product::where('group_id', $group->id)->orderBy('sort_order')->get()->keyBy('slug');
    expect($products->pluck('name')->take(4)->all())->toBe(['EsaBackup25', 'EsaBackup25 + Copia remota', 'EsaBackup100', 'EsaBackup100 + Copia remota'])
        ->and($products)->toHaveCount(12)
        ->and($products->every(fn (Product $product) => $product->hidden && $product->server_type === EsapanelModule::NAME))->toBeTrue()
        ->and($products['esabackup-1t']->config_options['package_name'])->toBe('27')
        ->and($products['esabackup-1t-copia']->config_options['package_name'])->toBe('28');

    // Yearly only up to 100 GB; the remote copy costs 70% more.
    expect(ebPrice('product', $products['esabackup-100']->id))->toBe([-1.0, 60.0])
        ->and(ebPrice('product', $products['esabackup-1t']->id))->toBe([45.0, 450.0])
        ->and(ebPrice('product', $products['esabackup-1t-copia']->id))->toBe([76.5, 765.0])
        ->and(ebPrice('product', $products['esabackup-reseller-10tb']->id))->toBe([260.0, 2600.0]);

    // Each product its own add-ons: none on 25 GB and the reseller plans,
    // no extra space on 100 GB, no extra accounts on 1 TB.
    $options = fn (string $slug) => ConfigOption::whereIn('group_id', $products[$slug]->configOptionGroups()->pluck('config_option_groups.id'))
        ->orderBy('sort_order')->pluck('option_name')->all();
    expect($products['esabackup-25']->configOptionGroups()->count())->toBe(0)
        ->and($products['esabackup-reseller-3tb']->configOptionGroups()->count())->toBe(0)
        ->and($options('esabackup-100'))->toBe(['addon|Account aggiuntivi', 'addon|Cartelle condivise aggiuntive', 'addon|Traffico aggiuntivo'])
        ->and($options('esabackup-1t'))->toBe(['addon|Spazio aggiuntivo', 'addon|Cartelle condivise aggiuntive', 'addon|Traffico aggiuntivo']);

    $choice = function (string $slug, string $option, string $addon) use ($products) {
        $optionId = ConfigOption::whereIn('group_id', $products[$slug]->configOptionGroups()->pluck('config_option_groups.id'))
            ->where('option_name', $option)->value('id');

        return ebPrice(ConfigOptionSub::PRICING_TYPE, ConfigOptionSub::where('config_id', $optionId)->where('option_name', 'like', $addon.'|%')->value('id'));
    };
    expect($choice('esabackup-500', 'addon|Spazio aggiuntivo', 'EsaBackup Spazio +300 GB'))->toBe([6.0, 60.0])
        ->and($choice('esabackup-500-copia', 'addon|Spazio aggiuntivo', 'EsaBackup Spazio +300 GB'))->toBe([10.2, 102.0])
        ->and($choice('esabackup-500-copia', 'addon|Account aggiuntivi', 'EsaBackup Account +10'))->toBe([3.0, 30.0])
        ->and($choice('esabackup-100', 'addon|Traffico aggiuntivo', 'EsaBackup Traffico +1 TB'))->toBe([-1.0, 50.0]);

    $this->artisan('esalogica:esabackup-products', ['--write' => true])
        ->expectsOutputToContain('Niente da cambiare.')
        ->assertSuccessful();
});

it('stops before writing when a plan or an add-on is missing on the server', function () {
    ebServer(['EsaBackup500 Copia remota', 'EsaBackup Cartelle +5']);

    $this->artisan('esalogica:esabackup-products', ['--write' => true])
        ->expectsOutputToContain('Sul server Esapanel mancano: EsaBackup500 Copia remota, EsaBackup Cartelle +5')
        ->assertFailed();

    expect(Product::where('slug', 'like', 'esabackup-%')->count())->toBe(0)
        ->and(ProductGroup::where('slug', 'esabackup')->exists())->toBeFalse();
});
