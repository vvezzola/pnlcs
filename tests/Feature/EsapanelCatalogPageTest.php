<?php

use App\Models\Admin;
use App\Models\AdminRole;
use App\Models\ConfigOption;
use App\Models\ConfigOptionGroup;
use App\Models\ConfigOptionSub;
use App\Models\Currency;
use App\Models\Pricing;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\Server;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Modules\Servers\EsapanelLicense\EsapanelCatalog;
use Modules\Servers\EsapanelLicense\EsapanelLicenseModule;

/*
 * The Esapanel catalogue page (modules/Servers/EsapanelLicense): a seller,
 * Esalogica or a partner, makes the licence products at its own prices from
 * the plans and add-ons its License Server account has.
 */

/** A License Server account with the level plans, the module add-ons and, if asked, the Trial plan. */
function cpLicenseServer(bool $trial = false): void
{
    $levels = ['LSP Company' => 70, 'LSP Integrator 30' => 71, 'LSP Integrator 60' => 72, 'LSP Integrator 100' => 73, 'LSP Provider' => 74]
        + ($trial ? ['LSP Trial' => 75] : []);
    $plans = collect($levels)->map(fn ($id, $label) => ['id' => $id, 'label' => $label, 'services' => [['code' => 'lsp.license', 'limits' => $label === 'LSP Trial'
        ? [['code' => 'lsp.license.trial', 'value' => 'true']] : []]]])->values()->all();
    $addons = collect(EsapanelCatalog::addonNames())->map(fn ($name, $i) => ['id' => 100 + $i, 'label' => $name])->all();

    Http::preventStrayRequests();
    Http::fake(['https://lsp.test:9943/api/v1/plans*' => fn (Request $request) => Http::response(['data' => ($request->data()['type'] ?? '') === 'client_addon' ? $addons : $plans])]);
}

/** @return array{server: Server, group: ProductGroup} */
function cpShop(): array
{
    Currency::query()->update(['is_default' => false]);
    Currency::updateOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'prefix' => '€', 'suffix' => '', 'rate' => 1, 'is_default' => true]);

    return [
        'server' => Server::factory()->create([
            'type' => EsapanelLicenseModule::NAME, 'name' => 'LSP', 'hostname' => 'lsp.test', 'ip_address' => '', 'port' => null,
            'access_hash' => 'partner-key', 'active' => true, 'disabled' => false,
        ]),
        'group' => ProductGroup::factory()->create(['name' => 'Licenze', 'slug' => 'licenze']),
    ];
}

function cpStaff(array $permissions = ['manage_products']): Admin
{
    return Admin::factory()->create(['role_id' => AdminRole::factory()->create(['is_full_admin' => false, 'permissions' => $permissions])->id]);
}

/** The partner's prices: CSP everywhere, UCP only on the Provider. */
function cpPrices(): array
{
    return [
        'csp' => [
            'company_small' => ['monthly' => '20', 'annually' => '200'], 'company_large' => ['monthly' => '35', 'annually' => '350'],
            'integrator_30' => ['monthly' => '100', 'annually' => '1000'], 'integrator_60' => ['monthly' => '150', 'annually' => '1500'],
            'integrator_100' => ['monthly' => '200', 'annually' => '2000'], 'provider' => ['monthly' => '400', 'annually' => '4000'],
        ],
        'ucp' => ['provider' => ['monthly' => '160', 'annually' => '1600']],
    ];
}

test('the page asks for a License Server first, and is for staff who manage products', function () {
    $this->actingAs(cpStaff(), 'admin')->get(route('admin.esapanel-catalog.index'))
        ->assertOk()->assertSee(__('esapanel-license::messages.page.title'))->assertDontSee('name="action"', false)
        // In the Extensions menu, which the module fills on its own.
        ->assertSee('href="'.route('admin.esapanel-catalog.index').'"', false);

    $this->actingAs(cpStaff(['list_products']), 'admin')->get(route('admin.esapanel-catalog.index'))->assertForbidden();
});

test('a preview tells what would be made and writes nothing', function () {
    ['server' => $server, 'group' => $group] = cpShop();
    cpLicenseServer();

    $this->actingAs(cpStaff(), 'admin')->post(route('admin.esapanel-catalog.store'), [
        'server_id' => $server->id, 'group_id' => $group->id, 'prices' => cpPrices(), 'action' => 'preview',
    ])->assertRedirect()->assertSessionHas('esapanel_catalog', fn ($result) => $result['ok'] && $result['changes'] > 0 && ! $result['written']);

    expect(Product::count())->toBe(0);
});

test('the partner\'s prices become the products, and a module with no price is not sold there', function () {
    ['server' => $server, 'group' => $group] = cpShop();
    cpLicenseServer();

    $this->actingAs(cpStaff(), 'admin')->post(route('admin.esapanel-catalog.store'), [
        'server_id' => $server->id, 'group_id' => $group->id, 'prices' => cpPrices(), 'visible' => '1', 'action' => 'write',
    ])->assertSessionHas('esapanel_catalog', fn ($result) => $result['ok'] && $result['written']);

    $provider = Product::where('slug', 'esapanel-provider')->first();
    $integrator = Product::where('slug', 'esapanel-integrator-30')->first();
    expect(Product::where('group_id', $group->id)->count())->toBe(5)
        ->and($provider->hidden)->toBeFalse()
        ->and($provider->config_options['package_name'])->toBe('74');

    $optionsOf = fn (Product $product) => ConfigOption::whereIn('group_id', $product->configOptionGroups()->pluck('config_option_groups.id'))->pluck('option_name')->all();
    expect($optionsOf($provider))->toEqualCanonicalizing(['addon|Central Syslog (CSP)', 'addon|Uptime Check (UCP)'])
        ->and($optionsOf($integrator))->toBe(['addon|Central Syslog (CSP)']);

    $ucp = ConfigOptionSub::where('option_name', 'LSP UCP|Uptime Check (UCP)')->first();
    $price = Pricing::where('type', ConfigOptionSub::PRICING_TYPE)->where('rel_id', $ucp->id)->first();
    expect((float) $price->monthly)->toBe(160.0)->and((float) $price->annually)->toBe(1600.0);

    // Opened afresh, the form shows the prices on sale.
    $this->flushSession();
    $this->actingAs(cpStaff(), 'admin')->get(route('admin.esapanel-catalog.index'))->assertOk()->assertSee('value="1600.00"', false);
});

test('the same catalogue in another language changes nothing', function () {
    ['server' => $server, 'group' => $group] = cpShop();
    cpLicenseServer();
    $catalog = app(EsapanelCatalog::class);

    app()->setLocale('it');
    $catalog->sync($server, $group, Currency::getDefault(), cpPrices(), false, true);
    app()->setLocale('en');
    $again = $catalog->sync($server, $group, Currency::getDefault(), cpPrices(), false, true);

    expect($again['changes'])->toBe(0)->and(ConfigOptionSub::count())->toBe(8);
});

test('once the License Server has the Trial plan, a free Trial sells every module', function () {
    ['server' => $server, 'group' => $group] = cpShop();
    cpLicenseServer(trial: true);

    app(EsapanelCatalog::class)->sync($server, $group, Currency::getDefault(), cpPrices(), false, true);

    $trial = Product::where('slug', EsapanelCatalog::TRIAL_SLUG)->first();
    expect($trial->pay_type)->toBe('free')
        ->and($trial->config_options['package_name'])->toBe('75')
        ->and($trial->config_options['addons'])->toBe(['LSP CSP', 'LSP CSPH', 'LSP UCP', 'LSP DRP', 'LSP WFP', 'LSP WFPH'])
        ->and($trial->configOptionGroups()->count())->toBe(0);
});

test('a level the seller gives no price at gets no product', function () {
    ['server' => $server, 'group' => $group] = cpShop();
    cpLicenseServer();

    app(EsapanelCatalog::class)->sync($server, $group, Currency::getDefault(), ['csp' => ['provider' => ['monthly' => '400', 'annually' => '4000']]], false, true);

    expect(Product::pluck('slug')->all())->toBe(['esapanel-provider']);
});

test('the option groups of the first command are taken over, not made twice', function () {
    ['server' => $server, 'group' => $group] = cpShop();
    cpLicenseServer();
    $catalog = app(EsapanelCatalog::class);
    $catalog->sync($server, $group, Currency::getDefault(), cpPrices(), false, true);
    ConfigOptionGroup::where('name', 'Esapanel provider modules')->update(['name' => 'Esapanel Provider - moduli']);

    $again = $catalog->sync($server, $group, Currency::getDefault(), cpPrices(), false, true);

    expect($again['changes'])->toBe(1)
        ->and(ConfigOptionGroup::where('name', 'like', 'Esapanel provider%')->count())->toBe(1)
        ->and(Product::where('slug', 'esapanel-provider')->first()->configOptionGroups()->count())->toBe(1);
});
