<?php

namespace Modules\Servers\EsapanelLicense;

use App\Models\ConfigOption;
use App\Models\ConfigOptionGroup;
use App\Models\ConfigOptionSub;
use App\Models\Currency;
use App\Models\Pricing;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\Server;
use Illuminate\Support\Facades\DB;

/**
 * The Esapanel licence products of a License Server: one product per level
 * (Company, Integrator 30, 60, 100, Provider) on the level's plan, with the
 * modules as configurable options ("addon|...") priced per month and per
 * year, each naming the add-on it sells; and, once the License Server has
 * the "LSP Trial" plan, a free Trial with every module.
 *
 * The plans and add-ons are read from the License Server by name, so the
 * same catalogue works for every partner and on the development server. The
 * prices are the seller's. sync() describes every change; it writes only
 * when told to, and a second run changes nothing.
 */
class EsapanelCatalog
{
    /** Level => License Server plan, in the order the products are shown. */
    public const LEVELS = [
        'company' => 'LSP Company',
        'integrator_30' => 'LSP Integrator 30',
        'integrator_60' => 'LSP Integrator 60',
        'integrator_100' => 'LSP Integrator 100',
        'provider' => 'LSP Provider',
    ];

    /** The price columns: the Company's two sizes, then the other levels. */
    public const PRICE_COLUMNS = ['company_small', 'company_large', 'integrator_30', 'integrator_60', 'integrator_100', 'provider'];

    /**
     * The modules: the add-on of Integrator and Provider, and the Company's
     * two sizes (add-on => size key).
     *
     * @var array<string, array{label: string, addon: string, company: array<string, string>}>
     */
    public const MODULES = [
        'csp' => ['label' => 'Central Syslog (CSP)', 'addon' => 'LSP CSP', 'company' => ['LSP CSP Company 25' => 'devices_25', 'LSP CSP Company 100' => 'devices_100']],
        'csph' => ['label' => 'Central Syslog secondario (CSPH)', 'addon' => 'LSP CSPH', 'company' => ['LSP CSPH Company 25' => 'devices_25', 'LSP CSPH Company 100' => 'devices_100']],
        'ucp' => ['label' => 'Uptime Check (UCP)', 'addon' => 'LSP UCP', 'company' => ['LSP UCP Company 25' => 'devices_25', 'LSP UCP Company 100' => 'devices_100']],
        'drp' => ['label' => 'DMARC Report (DRP)', 'addon' => 'LSP DRP', 'company' => ['LSP DRP Company 20' => 'domains_20', 'LSP DRP Company 100' => 'domains_100']],
        'wfp' => ['label' => 'Web File (WFP)', 'addon' => 'LSP WFP', 'company' => ['LSP WFP Company 2 TB' => 'space_2tb', 'LSP WFP Company Unlimited' => 'unlimited']],
        'wfph' => ['label' => 'Web File secondario (WFPH)', 'addon' => 'LSP WFPH', 'company' => ['LSP WFPH Company 2 TB' => 'space_2tb', 'LSP WFPH Company Unlimited' => 'unlimited']],
    ];

    /** The free Trial's plan; the Trial is offered once the License Server has it. */
    public const TRIAL_PLAN = 'LSP Trial';

    public const TRIAL_SLUG = 'esapanel-trial';

    /** @var list<string> */
    private const UNSOLD_CYCLES = ['quarterly', 'semiannually', 'biennially', 'triennially'];

    /** @var list<string> */
    private array $lines = [];

    private int $changes = 0;

    private bool $write = false;

    public function __construct(private EsapanelLicenseModule $module = new EsapanelLicenseModule) {}

    /** The slug of a level's product. */
    public static function slug(string $level): string
    {
        return 'esapanel-'.str_replace('_', '-', $level);
    }

    /**
     * The prices already on the products, in the shape sync() takes: to fill
     * the price form with what is on sale.
     *
     * @return array<string, array<string, array{monthly: string, annually: string}>>
     */
    public static function currentPrices(Currency $currency): array
    {
        $prices = [];
        foreach (array_keys(self::LEVELS) as $level) {
            $product = Product::where('slug', self::slug($level))->first();
            if (! $product) {
                continue;
            }
            $groupIds = $product->configOptionGroups()->pluck('config_option_groups.id');
            $options = ConfigOption::whereIn('group_id', $groupIds)->where('option_name', 'like', 'addon|%')->with('subs')->get();

            foreach ($options as $option) {
                foreach ($option->subs as $sub) {
                    [$addon] = explode('|', (string) $sub->option_name, 2);
                    [$module, $column] = self::columnOf($level, trim($addon));
                    if ($module === null) {
                        continue;
                    }
                    $row = Pricing::where('type', ConfigOptionSub::PRICING_TYPE)->where('rel_id', $sub->id)->where('currency_id', $currency->id)->first();
                    if ($row) {
                        $prices[$module][$column] = ['monthly' => self::money($row->monthly), 'annually' => self::money($row->annually)];
                    }
                }
            }
        }

        return $prices;
    }

    /**
     * Bring the products to the catalogue: what is missing is made, prices
     * that differ are set; nothing is deleted. A module with no price at a
     * level is not offered there.
     *
     * @param  array<string, array<string, array{monthly?: mixed, annually?: mixed}|null>>  $prices  module => price column => price
     * @return array{ok: bool, changes: int, lines: list<string>}
     */
    public function sync(Server $server, ProductGroup $group, Currency $currency, array $prices, bool $visible, bool $write): array
    {
        $this->lines = [];
        $this->changes = 0;
        $this->write = $write;

        try {
            $plans = collect($this->module->listPackages($server))
                ->mapWithKeys(fn (array $plan) => [preg_replace('/ \(Trial\)$/', '', $plan['name']) => (int) $plan['id']]);
            $addons = $this->module->listAddons($server);
        } catch (\Throwable $e) {
            return ['ok' => false, 'changes' => 0, 'lines' => [__('esapanel-license::messages.catalog.unreachable', ['error' => $e->getMessage()])]];
        }

        $missing = array_merge(
            array_values(array_diff(array_values(self::LEVELS), $plans->keys()->all())),
            array_values(array_diff(self::addonNames(), array_keys($addons))),
        );
        if ($missing !== []) {
            return ['ok' => false, 'changes' => 0, 'lines' => [__('esapanel-license::messages.catalog.missing', ['names' => implode(', ', $missing)])]];
        }

        DB::transaction(function () use ($group, $currency, $prices, $visible, $plans) {
            foreach (self::LEVELS as $level => $plan) {
                $this->lines[] = __('esapanel-license::messages.catalog.level', ['name' => self::levelName($level), 'plan' => $plan, 'id' => $plans[$plan]]);
                // A level the seller gives no price at is not sold: no product
                // made for it (one already on sale is left as it is).
                if (! self::pricedAt($level, $prices) && ! Product::where('slug', self::slug($level))->exists()) {
                    $this->lines[] = '  '.__('esapanel-license::messages.catalog.not_sold');

                    continue;
                }
                $product = $this->product(self::slug($level), self::levelName($level), self::levelDescription($level), $group, $currency, $plans[$plan], $visible, false, []);
                $this->moduleOptions($level, $product, $currency, $prices);
            }

            if ($plans->has(self::TRIAL_PLAN)) {
                $this->lines[] = __('esapanel-license::messages.catalog.level', ['name' => self::levelName('trial'), 'plan' => self::TRIAL_PLAN, 'id' => $plans[self::TRIAL_PLAN]]);
                $this->product(self::TRIAL_SLUG, self::levelName('trial'), self::levelDescription('trial'), $group, $currency,
                    $plans[self::TRIAL_PLAN], $visible, true, array_column(self::MODULES, 'addon'));
            } else {
                $this->lines[] = __('esapanel-license::messages.catalog.no_trial', ['plan' => self::TRIAL_PLAN]);
            }
        });

        return ['ok' => true, 'changes' => $this->changes, 'lines' => $this->lines];
    }

    /** @param  array<string, array<string, mixed>>  $prices */
    private static function pricedAt(string $level, array $prices): bool
    {
        $columns = $level === 'company' ? ['company_small', 'company_large'] : [$level];
        foreach (array_keys(self::MODULES) as $module) {
            foreach ($columns as $column) {
                if (self::price($prices[$module][$column] ?? null) !== null) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return list<string> every add-on the catalogue sells */
    public static function addonNames(): array
    {
        $names = [];
        foreach (self::MODULES as $module) {
            $names[] = $module['addon'];
            array_push($names, ...array_keys($module['company']));
        }

        return $names;
    }

    /** The module's name, the same in every language: options are found by it. */
    public static function moduleLabel(string $module): string
    {
        return self::MODULES[$module]['label'];
    }

    public static function levelName(string $level): string
    {
        return __('esapanel-license::messages.catalog.name.'.$level);
    }

    private static function levelDescription(string $level): string
    {
        return __('esapanel-license::messages.catalog.description.'.$level);
    }

    /**
     * A product on its plan. A Trial is free and sells a fixed set of add-ons
     * (config_options "addons") instead of options.
     *
     * @param  list<string>  $fixedAddons
     */
    private function product(string $slug, string $name, string $description, ProductGroup $group, Currency $currency, int $planId, bool $visible, bool $trial, array $fixedAddons): ?Product
    {
        $product = Product::where('slug', $slug)->first();
        $config = ['package_name' => (string) $planId] + ($fixedAddons !== [] ? ['addons' => $fixedAddons] : []);

        if (! $product) {
            $this->change(__('esapanel-license::messages.catalog.new_product', ['name' => $name])
                .($visible ? '' : ' '.__('esapanel-license::messages.catalog.hidden')));
            if (! $this->write) {
                return null;
            }

            $product = Product::create([
                'type' => 'other',
                'group_id' => $group->id,
                'name' => $name,
                'slug' => $slug,
                'description' => $description,
                'hidden' => ! $visible,
                'show_domain_options' => false,
                'pay_type' => $trial ? 'free' : 'recurring',
                'auto_setup' => $trial ? 'order' : 'payment',
                'server_type' => EsapanelLicenseModule::NAME,
                'config_options' => $config,
                'tax' => true,
                'sort_order' => $trial ? 0 : array_search($slug, array_map(self::slug(...), array_keys(self::LEVELS)), true) + 1,
            ]);
            // A level costs nothing by itself: its price is its modules'.
            Pricing::create(['type' => 'product', 'rel_id' => $product->id, 'currency_id' => $currency->id]
                + ($trial ? ['monthly' => -1, 'annually' => -1] : ['monthly' => 0, 'annually' => 0])
                + array_fill_keys(self::UNSOLD_CYCLES, -1));

            return $product;
        }

        $current = (array) $product->config_options;
        if (($current['package_name'] ?? null) !== $config['package_name'] || ($current['addons'] ?? []) !== ($config['addons'] ?? [])) {
            $this->change(__('esapanel-license::messages.catalog.plan_changed', ['name' => $name, 'id' => $planId]));
            if ($this->write) {
                $product->update(['config_options' => array_merge($current, $config)]);
            }
        }
        if ($visible && $product->hidden) {
            $this->change(__('esapanel-license::messages.catalog.on_sale', ['name' => $name]));
            if ($this->write) {
                $product->update(['hidden' => false]);
            }
        }

        return $product;
    }

    /** @param  array<string, array<string, array{monthly?: mixed, annually?: mixed}|null>>  $prices */
    private function moduleOptions(string $level, ?Product $product, Currency $currency, array $prices): void
    {
        // Found again by its name, so the name does not follow the language.
        $groupName = 'Esapanel '.$level.' modules';
        $group = ConfigOptionGroup::where('name', $groupName)->first();

        // The first version of Esalogica's command named it "Esapanel
        // Company - moduli": taken over and renamed, not made twice.
        $legacy = $group ? null : ConfigOptionGroup::where('name', self::levelName($level).' - moduli')->first();
        if ($legacy) {
            $this->change(__('esapanel-license::messages.catalog.renamed_group', ['from' => $legacy->name, 'to' => $groupName]));
            if ($this->write) {
                $legacy->update(['name' => $groupName]);
            }
            $group = $legacy;
        }

        if (! $group) {
            $this->change(__('esapanel-license::messages.catalog.new_group', ['name' => $groupName]));
            if ($this->write) {
                $group = ConfigOptionGroup::create(['name' => $groupName, 'description' => self::levelName($level)]);
            }
        }
        if ($group && $product && ! $product->configOptionGroups()->whereKey($group->id)->exists()) {
            $this->change(__('esapanel-license::messages.catalog.linked'));
            if ($this->write) {
                $product->configOptionGroups()->attach($group->id);
            }
        }

        $company = $level === 'company';
        $position = 0;
        foreach (self::MODULES as $key => $module) {
            $position++;
            $label = self::moduleLabel($key);
            $subs = [];
            if ($company) {
                foreach (array_keys($module['company']) as $i => $addon) {
                    $price = self::price($prices[$key][self::PRICE_COLUMNS[$i]] ?? null);
                    if ($price !== null) {
                        $subs[$addon.'|'.__('esapanel-license::messages.catalog.size.'.$module['company'][$addon])] = $price;
                    }
                }
                if ($subs !== []) {
                    $subs = ['none|'.__('esapanel-license::messages.catalog.none') => ['0.00', '0.00']] + $subs;
                }
            } else {
                $price = self::price($prices[$key][$level] ?? null);
                if ($price !== null) {
                    $subs[$module['addon'].'|'.$label] = $price;
                }
            }
            if ($subs === []) {
                continue;
            }

            $optionName = 'addon|'.$label;
            $option = $group ? ConfigOption::where('group_id', $group->id)->where('option_name', $optionName)->first() : null;
            if (! $option) {
                $this->change(__('esapanel-license::messages.catalog.new_option', ['name' => $label, 'prices' => implode(', ', array_map(
                    fn ($name, $price) => explode('|', $name, 2)[1].' '.$price[0].'/'.$price[1], array_keys($subs), $subs))]));
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
            $created = $option->wasRecentlyCreated;

            $order = 0;
            foreach ($subs as $subName => [$monthly, $annually]) {
                // By what it sells, not by its label, which follows the language.
                $sub = ConfigOptionSub::where('config_id', $option->id)
                    ->where('option_name', 'like', str_replace(['%', '_'], ['\\%', '\\_'], explode('|', $subName, 2)[0]).'|%')->first();
                if (! $sub) {
                    if (! $created) {
                        $this->change(__('esapanel-license::messages.catalog.new_choice', ['name' => $label, 'choice' => explode('|', $subName, 2)[1]]));
                    }
                    if (! $this->write) {
                        continue;
                    }
                    $sub = ConfigOptionSub::create(['config_id' => $option->id, 'option_name' => $subName, 'sort_order' => $order, 'hidden' => false]);
                }
                $order++;

                $row = Pricing::where('type', ConfigOptionSub::PRICING_TYPE)->where('rel_id', $sub->id)->where('currency_id', $currency->id)->first();
                if ($row && self::money($row->monthly) === $monthly && self::money($row->annually) === $annually) {
                    continue;
                }
                if ($row) {
                    $this->change(__('esapanel-license::messages.catalog.price', [
                        'name' => $label, 'choice' => explode('|', $subName, 2)[1],
                        'from' => self::money($row->monthly).'/'.self::money($row->annually), 'to' => $monthly.'/'.$annually,
                    ]));
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

    /**
     * A price from the form or the price list, as [monthly, yearly] with two
     * decimals; null when the module is not sold there (both left empty).
     *
     * @param  array{monthly?: mixed, annually?: mixed}|null  $price
     * @return array{0: string, 1: string}|null
     */
    private static function price(?array $price): ?array
    {
        $monthly = trim((string) ($price['monthly'] ?? ''));
        $annually = trim((string) ($price['annually'] ?? ''));
        if ($monthly === '' && $annually === '') {
            return null;
        }

        return [self::money($monthly === '' ? -1 : $monthly), self::money($annually === '' ? -1 : $annually)];
    }

    private static function money(mixed $value): string
    {
        return number_format((float) str_replace(',', '.', (string) $value), 2, '.', '');
    }

    /**
     * The module and price column an add-on sells at a level.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private static function columnOf(string $level, string $addon): array
    {
        foreach (self::MODULES as $key => $module) {
            if ($level === 'company') {
                $index = array_search($addon, array_keys($module['company']), true);
                if ($index !== false) {
                    return [$key, self::PRICE_COLUMNS[$index]];
                }
            } elseif ($addon === $module['addon']) {
                return [$key, $level];
            }
        }

        return [null, null];
    }

    private function change(string $line): void
    {
        $this->changes++;
        $this->lines[] = '  '.$line;
    }
}
