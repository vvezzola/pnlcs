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
use Modules\Servers\EsapanelLicense\EsapanelLicenseModule;

/*
 * Esalogica only: the Esapanel licences of the 2027 price list, one product
 * per level with the modules as priced "addon|..." options, the plans and
 * add-ons read from the License Server.
 */

beforeEach(fn () => app()->setLocale('it'));

/** A License Server with the five level plans and the module add-ons, less the ones named. */
function epLicenseServer(array $withoutAddons = []): void
{
    $levels = ['LSP Company' => 70, 'LSP Integrator 30' => 71, 'LSP Integrator 60' => 72, 'LSP Integrator 100' => 73, 'LSP Provider' => 74];
    $plans = collect($levels)->map(fn ($id, $label) => ['id' => $id, 'label' => $label, 'services' => [['code' => 'lsp.license', 'limits' => []]]])->values()->all();

    $names = [];
    foreach (['CSP' => ['25', '100'], 'CSPH' => ['25', '100'], 'UCP' => ['25', '100'], 'DRP' => ['20', '100'], 'WFP' => ['2 TB', 'Unlimited'], 'WFPH' => ['2 TB', 'Unlimited']] as $module => $sizes) {
        $names[] = "LSP {$module}";
        foreach ($sizes as $size) {
            $names[] = "LSP {$module} Company {$size}";
        }
    }
    $addons = collect($names)->reject(fn ($name) => in_array($name, $withoutAddons, true))->values()
        ->map(fn ($name, $i) => ['id' => 100 + $i, 'label' => $name])->all();

    Http::preventStrayRequests();
    Http::fake(['https://lsp.test:9943/api/v1/plans*' => fn (Request $request) => Http::response(['data' => ($request->data()['type'] ?? '') === 'client_addon' ? $addons : $plans])]);
}

function epShop(): void
{
    Currency::query()->update(['is_default' => false]);
    Currency::updateOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'prefix' => '€', 'suffix' => '', 'rate' => 1, 'is_default' => true]);
    ProductGroup::factory()->create(['name' => 'EsaPanel', 'slug' => 'esapanel']);
    Server::factory()->create([
        'type' => EsapanelLicenseModule::NAME, 'hostname' => 'lsp.test', 'ip_address' => '', 'port' => null,
        'access_hash' => 'key', 'active' => true, 'disabled' => false,
    ]);
}

/** @return array{0: string, 1: string} monthly and yearly price of a sub-option */
function epPrice(ConfigOptionSub $sub): array
{
    $row = Pricing::where('type', ConfigOptionSub::PRICING_TYPE)->where('rel_id', $sub->id)->first();

    return [number_format((float) $row->monthly, 2, '.', ''), number_format((float) $row->annually, 2, '.', '')];
}

test('the dry run lists the products and options and writes nothing', function () {
    epShop();
    epLicenseServer();

    $this->artisan('esalogica:esapanel-products')
        ->expectsOutputToContain('+ prodotto Esapanel Company (nascosto)')
        ->expectsOutputToContain('+ Central Syslog (CSP): Nessuno 0.00/0.00, 25 device 15.00/150.00, 100 device 29.00/290.00')
        ->expectsOutputToContain('Aggiungi --write')
        ->assertSuccessful();

    expect(Product::count())->toBe(0)->and(ConfigOption::count())->toBe(0);
});

test('--write makes the five levels on their plans, the modules priced per level, once', function () {
    epShop();
    epLicenseServer();

    $this->artisan('esalogica:esapanel-products --write')->assertSuccessful();

    $company = Product::where('slug', 'esapanel-company')->first();
    $integrator = Product::where('slug', 'esapanel-integrator-60')->first();
    expect(Product::count())->toBe(5)
        ->and($company->hidden)->toBeTrue()
        ->and($company->server_type)->toBe(EsapanelLicenseModule::NAME)
        ->and($company->config_options['package_name'])->toBe('70')
        ->and($integrator->config_options['package_name'])->toBe('72');

    $wfp = ConfigOption::whereIn('group_id', $company->configOptionGroups()->pluck('config_option_groups.id'))
        ->where('option_name', 'addon|Web File (WFP)')->first();
    expect($wfp->option_type)->toBe('dropdown')
        ->and($wfp->subs->pluck('option_name')->all())->toBe(['none|Nessuno', 'LSP WFP Company 2 TB|2 TB', 'LSP WFP Company Unlimited|illimitato'])
        ->and(epPrice($wfp->subs[2]))->toBe(['36.00', '360.00']);

    $csp = ConfigOption::whereIn('group_id', $integrator->configOptionGroups()->pluck('config_option_groups.id'))
        ->where('option_name', 'addon|Central Syslog (CSP)')->first();
    expect($csp->option_type)->toBe('checkbox')
        ->and($csp->subs->pluck('option_name')->all())->toBe(['LSP CSP|Central Syslog (CSP)'])
        ->and(epPrice($csp->subs[0]))->toBe(['140.00', '1400.00']);

    $this->artisan('esalogica:esapanel-products --write')
        ->expectsOutputToContain('Niente da cambiare')
        ->assertSuccessful();
    expect(Product::count())->toBe(5)->and(ConfigOption::count())->toBe(30);
});

test('nothing is made when the License Server lacks an add-on of the price list', function () {
    epShop();
    epLicenseServer(['LSP CSPH Company 100']);

    $this->artisan('esalogica:esapanel-products --write')
        ->expectsOutputToContain('LSP CSPH Company 100')
        ->assertFailed();

    expect(Product::count())->toBe(0);
});

test('without a License Server the command says what to set up', function () {
    Currency::updateOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'prefix' => '€', 'suffix' => '', 'rate' => 1, 'is_default' => true]);
    ProductGroup::factory()->create(['slug' => 'esapanel']);

    $this->artisan('esalogica:esapanel-products')
        ->expectsOutputToContain('Manca il License Server')
        ->assertFailed();
});
