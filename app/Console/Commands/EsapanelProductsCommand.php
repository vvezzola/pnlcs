<?php

namespace App\Console\Commands;

use App\Models\ConfigOption;
use App\Models\ConfigOptionGroup;
use App\Models\ConfigOptionSub;
use App\Models\Currency;
use App\Models\Pricing;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\Server;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Servers\EsapanelLicense\EsapanelLicenseModule;

/**
 * Esalogica only: the Esapanel licences of the 2027 price list. One product
 * per level, sold on the License Server's level plan; the modules are its
 * configurable options ("addon|..."), priced per month and per year, each
 * naming the License Server add-on it sells. The plans and add-ons are read
 * from the License Server, so no id is written here. Shows every change
 * first; writes only with --write, and running it again changes nothing.
 */
class EsapanelProductsCommand extends Command
{
    protected $signature = 'esalogica:esapanel-products
        {--write : Write to the database; without it nothing is changed}
        {--visible : Put the products on sale; without it new ones stay hidden}';

    protected $description = 'Esalogica: Esapanel licence products of the 2027 price list (dry run unless --write)';

    /** @var array<string, array{name: string, plan: string, description: string}> */
    private const LEVELS = [
        'esapanel-company' => ['name' => 'Esapanel Company', 'plan' => 'LSP Company', 'description' => 'Licenza Esapanel per la propria azienda. Scegli i moduli e, per ognuno, la taglia.'],
        'esapanel-integrator-30' => ['name' => 'Esapanel Integrator 30', 'plan' => 'LSP Integrator 30', 'description' => 'Licenza Esapanel per chi gestisce i propri clienti, fino a 30. Scegli i moduli.'],
        'esapanel-integrator-60' => ['name' => 'Esapanel Integrator 60', 'plan' => 'LSP Integrator 60', 'description' => 'Licenza Esapanel per chi gestisce i propri clienti, fino a 60. Scegli i moduli.'],
        'esapanel-integrator-100' => ['name' => 'Esapanel Integrator 100', 'plan' => 'LSP Integrator 100', 'description' => 'Licenza Esapanel per chi gestisce i propri clienti, fino a 100. Scegli i moduli.'],
        'esapanel-provider' => ['name' => 'Esapanel Provider', 'plan' => 'LSP Provider', 'description' => 'Licenza Esapanel per provider, clienti illimitati. Scegli i moduli.'],
    ];

    /**
     * The modules: the option label, the add-on of Integrator and Provider,
     * and the two Company sizes (add-on => label).
     *
     * @var array<string, array{label: string, addon: string, company: array<string, string>}>
     */
    private const MODULES = [
        'csp' => ['label' => 'Central Syslog (CSP)', 'addon' => 'LSP CSP', 'company' => ['LSP CSP Company 25' => '25 device', 'LSP CSP Company 100' => '100 device']],
        'csph' => ['label' => 'Central Syslog secondario (CSPH)', 'addon' => 'LSP CSPH', 'company' => ['LSP CSPH Company 25' => '25 device', 'LSP CSPH Company 100' => '100 device']],
        'ucp' => ['label' => 'Uptime Check (UCP)', 'addon' => 'LSP UCP', 'company' => ['LSP UCP Company 25' => '25 device', 'LSP UCP Company 100' => '100 device']],
        'drp' => ['label' => 'DMARC Report (DRP)', 'addon' => 'LSP DRP', 'company' => ['LSP DRP Company 20' => '20 domini', 'LSP DRP Company 100' => '100 domini']],
        'wfp' => ['label' => 'Web File (WFP)', 'addon' => 'LSP WFP', 'company' => ['LSP WFP Company 2 TB' => '2 TB', 'LSP WFP Company Unlimited' => 'illimitato']],
        'wfph' => ['label' => 'Web File secondario (WFPH)', 'addon' => 'LSP WFPH', 'company' => ['LSP WFPH Company 2 TB' => '2 TB', 'LSP WFPH Company Unlimited' => 'illimitato']],
    ];

    /**
     * The 2027 price list, EUR without VAT: module => [monthly, yearly] for
     * Company small, Company large, Integrator 30, 60, 100, Provider. CSP and
     * CSPH Company 25 as corrected by the user (the PDF said 150 and 75).
     *
     * @var array<string, list<array{0: string, 1: string}>>
     */
    private const PRICES = [
        'csp' => [['15.00', '150.00'], ['29.00', '290.00'], ['90.00', '900.00'], ['140.00', '1400.00'], ['190.00', '1900.00'], ['360.00', '3600.00']],
        'csph' => [['7.50', '75.00'], ['14.50', '145.00'], ['45.00', '450.00'], ['70.00', '700.00'], ['95.00', '950.00'], ['180.00', '1800.00']],
        'ucp' => [['12.00', '120.00'], ['25.00', '250.00'], ['60.00', '600.00'], ['79.00', '790.00'], ['99.00', '990.00'], ['150.00', '1500.00']],
        'drp' => [['9.00', '90.00'], ['19.00', '190.00'], ['29.00', '290.00'], ['50.00', '500.00'], ['80.00', '800.00'], ['120.00', '1200.00']],
        'wfp' => [['12.00', '120.00'], ['36.00', '360.00'], ['120.00', '1200.00'], ['190.00', '1900.00'], ['260.00', '2600.00'], ['480.00', '4800.00']],
        'wfph' => [['6.00', '60.00'], ['18.00', '180.00'], ['60.00', '600.00'], ['95.00', '950.00'], ['130.00', '1300.00'], ['240.00', '2400.00']],
    ];

    /** The column of PRICES for each non-Company level. */
    private const LEVEL_COLUMN = ['esapanel-integrator-30' => 2, 'esapanel-integrator-60' => 3, 'esapanel-integrator-100' => 4, 'esapanel-provider' => 5];

    /** @var list<string> */
    private const UNSOLD_CYCLES = ['quarterly', 'semiannually', 'biennially', 'triennially'];

    private bool $write = false;

    private int $changes = 0;

    public function handle(): int
    {
        $this->write = (bool) $this->option('write');
        // The same instance answers every call in one process (the tests).
        $this->changes = 0;

        $currency = Currency::where('code', 'EUR')->first();
        $group = ProductGroup::where('slug', 'esapanel')->first();
        $server = Server::where('type', EsapanelLicenseModule::NAME)->where('active', true)->orderBy('id')->first();
        if (! $currency || ! $group || ! $server) {
            $this->error(match (true) {
                ! $currency => 'Nessuna valuta EUR.',
                ! $group => 'Manca il gruppo prodotti "esapanel".',
                default => 'Manca il License Server: aggiungilo in Setup, Server (tipo Esapanel License, porta 9943, chiave API).',
            });

            return self::FAILURE;
        }

        $module = new EsapanelLicenseModule;
        try {
            $plans = collect($module->listPackages($server))->mapWithKeys(fn (array $plan) => [$plan['name'] => (int) $plan['id']]);
            $addons = $module->listAddons($server);
        } catch (\Throwable $e) {
            $this->error('License Server: '.$e->getMessage());

            return self::FAILURE;
        }

        $missing = array_merge(
            array_values(array_diff(array_column(self::LEVELS, 'plan'), $plans->keys()->all())),
            array_values(array_diff($this->addonNames(), array_keys($addons))),
        );
        if ($missing !== []) {
            $this->error('Il License Server non ha: '.implode(', ', $missing));

            return self::FAILURE;
        }

        DB::transaction(function () use ($currency, $group, $server, $plans) {
            foreach (self::LEVELS as $slug => $level) {
                $this->line($level['name'].' (piano '.$level['plan'].' #'.$plans[$level['plan']].')');
                $product = $this->product($slug, $level, $currency, $group, $server, $plans[$level['plan']]);
                $this->moduleOptions($slug, $level, $product, $currency);
            }
        });

        if ($this->changes === 0) {
            $this->info('Niente da cambiare.');
        } elseif ($this->write) {
            $this->info('Fatto.');
        } else {
            $this->line('Prova: nulla è stato scritto. Aggiungi --write per applicare.');
        }

        return self::SUCCESS;
    }

    /** @return list<string> */
    private function addonNames(): array
    {
        $names = [];
        foreach (self::MODULES as $module) {
            $names[] = $module['addon'];
            array_push($names, ...array_keys($module['company']));
        }

        return $names;
    }

    /** @param  array{name: string, plan: string, description: string}  $level */
    private function product(string $slug, array $level, Currency $currency, ProductGroup $group, Server $server, int $planId): ?Product
    {
        $product = Product::where('slug', $slug)->first();
        $config = ['package_name' => (string) $planId];

        if (! $product) {
            $this->change("  + prodotto {$level['name']}".($this->option('visible') ? '' : ' (nascosto)'));
            if (! $this->write) {
                return null;
            }
            $product = Product::create([
                'type' => 'other',
                'group_id' => $group->id,
                'name' => $level['name'],
                'slug' => $slug,
                'description' => $level['description'],
                'hidden' => ! $this->option('visible'),
                'show_domain_options' => false,
                'pay_type' => 'recurring',
                'auto_setup' => 'payment',
                'server_type' => EsapanelLicenseModule::NAME,
                'server_group_id' => null,
                'config_options' => $config,
                'tax' => true,
                'sort_order' => array_search($slug, array_keys(self::LEVELS), true) + 1,
            ]);
            // The level costs nothing by itself: the price is its modules'.
            Pricing::create(['type' => 'product', 'rel_id' => $product->id, 'currency_id' => $currency->id, 'monthly' => 0, 'annually' => 0]
                + array_fill_keys(self::UNSOLD_CYCLES, -1));

            return $product;
        }

        if (($product->config_options['package_name'] ?? null) !== (string) $planId) {
            $this->change('  * piano: '.($product->config_options['package_name'] ?? '-')." -> {$planId}");
            if ($this->write) {
                $product->update(['config_options' => array_merge((array) $product->config_options, $config)]);
            }
        }
        if ($this->option('visible') && $product->hidden) {
            $this->change('  * in vendita');
            if ($this->write) {
                $product->update(['hidden' => false]);
            }
        }

        return $product;
    }

    /** @param  array{name: string, plan: string, description: string}  $level */
    private function moduleOptions(string $slug, array $level, ?Product $product, Currency $currency): void
    {
        $groupName = $level['name'].' - moduli';
        $group = ConfigOptionGroup::where('name', $groupName)->first();
        if (! $group) {
            $this->change("  + opzioni: {$groupName}");
            if ($this->write) {
                $group = ConfigOptionGroup::create(['name' => $groupName, 'description' => 'I moduli della licenza '.$level['name']]);
            }
        }
        if ($group && $product && ! $product->configOptionGroups()->whereKey($group->id)->exists()) {
            $this->change('  * opzioni collegate al prodotto');
            if ($this->write) {
                $product->configOptionGroups()->attach($group->id);
            }
        }

        $company = $slug === 'esapanel-company';
        $position = 0;
        foreach (self::MODULES as $key => $module) {
            $position++;
            $optionName = 'addon|'.$module['label'];
            $subs = $company
                ? ['none|Nessuno' => ['0.00', '0.00']] + [
                    array_keys($module['company'])[0].'|'.array_values($module['company'])[0] => self::PRICES[$key][0],
                    array_keys($module['company'])[1].'|'.array_values($module['company'])[1] => self::PRICES[$key][1],
                ]
                : [$module['addon'].'|'.$module['label'] => self::PRICES[$key][self::LEVEL_COLUMN[$slug]]];

            $option = $group ? ConfigOption::where('group_id', $group->id)->where('option_name', $optionName)->first() : null;
            if (! $option) {
                $prices = implode(', ', array_map(fn ($name, $price) => explode('|', $name, 2)[1].' '.$price[0].'/'.$price[1], array_keys($subs), $subs));
                $this->change("  + {$module['label']}: {$prices}");
                if (! $this->write) {
                    continue;
                }
                $option = ConfigOption::create([
                    'group_id' => $group->id,
                    'option_name' => $optionName,
                    'option_type' => $company ? 'dropdown' : 'checkbox',
                    'sort_order' => $position,
                    'hidden' => false,
                ]);
            }

            $order = 0;
            foreach ($subs as $subName => [$monthly, $annually]) {
                $sub = ConfigOptionSub::where('config_id', $option->id)->where('option_name', $subName)->first();
                if (! $sub) {
                    if ($option->wasRecentlyCreated === false) {
                        $this->change("  + {$module['label']}: ".explode('|', $subName, 2)[1]);
                    }
                    if (! $this->write) {
                        continue;
                    }
                    $sub = ConfigOptionSub::create(['config_id' => $option->id, 'option_name' => $subName, 'sort_order' => $order, 'hidden' => false]);
                }
                $order++;

                $pricing = Pricing::where('type', ConfigOptionSub::PRICING_TYPE)->where('rel_id', $sub->id)->where('currency_id', $currency->id)->first();
                $same = $pricing
                    && number_format((float) $pricing->monthly, 2, '.', '') === $monthly
                    && number_format((float) $pricing->annually, 2, '.', '') === $annually;
                if ($same) {
                    continue;
                }
                if ($pricing) {
                    $this->change("  * {$module['label']} ".explode('|', $subName, 2)[1].": {$pricing->monthly}/{$pricing->annually} -> {$monthly}/{$annually}");
                }
                if ($this->write) {
                    Pricing::updateOrCreate(
                        ['type' => ConfigOptionSub::PRICING_TYPE, 'rel_id' => $sub->id, 'currency_id' => $currency->id],
                        ['monthly' => $monthly, 'annually' => $annually] + array_fill_keys(self::UNSOLD_CYCLES, -1),
                    );
                }
            }
        }
    }

    private function change(string $line): void
    {
        $this->changes++;
        $this->line($line);
    }
}
