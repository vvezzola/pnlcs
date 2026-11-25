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
use Modules\Servers\Esapanel\EsapanelModule;

/**
 * Esalogica only: the EsaBackup products of the 2027 price list, on the
 * EsaBackup plans of an Esapanel server (the Esapanel module). One product
 * per plan in the group "esabackup", the space and account add-ons as priced
 * "addon|..." options of the paid plans. Plans and add-ons are found on the
 * server by their exact names. Shows every change first; writes only with
 * --write, and running it again changes nothing. Nothing is ever deleted.
 */
class EsabackupProductsCommand extends Command
{
    protected $signature = 'esalogica:esabackup-products
        {--server= : The Esapanel server record (id); the first active one if left out}
        {--write : Write to the database; without it nothing is changed}
        {--visible : Put the products on sale; without it new ones stay hidden}';

    protected $description = 'Esalogica: EsaBackup products of the 2027 price list (dry run unless --write)';

    /**
     * slug => [name, Esapanel plan, monthly, yearly, with the add-on options,
     * description]. Prices in EUR without VAT; null prices make a free
     * product (the trial).
     *
     * @var array<string, array{0: string, 1: string, 2: ?string, 3: ?string, 4: bool, 5: string}>
     */
    private const PRODUCTS = [
        'esabackup-prova' => ['EsaBackup Prova', 'EsaBackup Prova', null, null, false,
            'Prova gratuita di 30 giorni: 10 GB, 20 GB di traffico al mese, 2 account. Una per cliente.'],
        'esabackup-100' => ['EsaBackup100', 'EsaBackup100', '6.00', '60.00', true,
            '100 GB per la copia fuori sede dei backup di NAS, server e PC. 200 GB di traffico al mese, 3 account, sorveglianza delle copie e report. Datacenter nell\'Unione Europea (Amsterdam).'],
        'esabackup-500' => ['EsaBackup500', 'EsaBackup500', '18.00', '180.00', true,
            '500 GB per la copia fuori sede dei backup di NAS, server e PC. 1 TB di traffico al mese, 10 account, 5 cartelle condivise, sorveglianza delle copie e report. Datacenter nell\'Unione Europea (Amsterdam).'],
        'esabackup-1t' => ['EsaBackup1T', 'EsaBackup1T', '29.00', '290.00', true,
            '1 TB per la copia fuori sede dei backup di NAS, server e PC. 2 TB di traffico al mese, 50 account, 20 cartelle condivise, storico di 2 anni, sorveglianza delle copie e report. Datacenter nell\'Unione Europea (Amsterdam).'],
        'esabackup-reseller-10tb' => ['EsaBackup Reseller 10 TB', 'EsaBackup Reseller 10 TB', '180.00', '1800.00', false,
            'Per rivendere EsaBackup con il proprio marchio: 10 TB e 15 TB di traffico al mese da distribuire fino a 50 clienti, nel pannello Esapanel e dal proprio PNLCS.'],
    ];

    /**
     * The add-on options of the paid plans: option label => [Esapanel add-on
     * => [choice label, monthly, yearly]]. Every option starts with "none".
     *
     * @var array<string, array<string, array{0: string, 1: string, 2: string}>>
     */
    private const OPTIONS = [
        'Spazio aggiuntivo' => [
            'EsaBackup Spazio +100 GB' => ['+100 GB', '3.00', '30.00'],
            'EsaBackup Spazio +200 GB' => ['+200 GB', '6.00', '60.00'],
            'EsaBackup Spazio +500 GB' => ['+500 GB', '12.00', '120.00'],
            'EsaBackup Spazio +1 TB' => ['+1 TB', '18.00', '180.00'],
            'EsaBackup Spazio +2 TB' => ['+2 TB', '34.00', '340.00'],
        ],
        'Account aggiuntivi' => [
            'EsaBackup Account +5' => ['+5 account', '2.00', '20.00'],
        ],
    ];

    /** How long the free trial lasts (the Esapanel module suspends it after). */
    private const TRIAL_DAYS = 30;

    /** The option group, found again by its name. */
    private const OPTION_GROUP = 'EsaBackup extras';

    /** @var list<string> */
    private const UNSOLD_CYCLES = ['quarterly', 'semiannually', 'biennially', 'triennially'];

    private int $changes = 0;

    private bool $write = false;

    public function handle(): int
    {
        // The command object lives on between calls in the same process.
        $this->changes = 0;
        $this->write = (bool) $this->option('write');
        $currency = Currency::where('code', 'EUR')->first();
        $server = Server::where('type', EsapanelModule::NAME)->where('active', true)
            ->when($this->option('server'), fn ($q, $id) => $q->whereKey((int) $id))
            ->orderBy('id')->first();

        if (! $currency || ! $server) {
            $this->error(! $currency ? 'Nessuna valuta EUR.' : 'Manca il server Esapanel: aggiungilo in Setup, Server (tipo Esapanel, porta 9943, chiave API).');

            return self::FAILURE;
        }

        $module = new EsapanelModule;
        try {
            $plans = $this->planIds($module->listPackages($server));
            $addons = $module->listAddons($server);
        } catch (\RuntimeException $e) {
            $this->error('Il server Esapanel non risponde: '.$e->getMessage());

            return self::FAILURE;
        }

        $missing = array_values(array_merge(
            array_diff(array_column(self::PRODUCTS, 1), array_keys($plans)),
            array_diff(array_merge(...array_map('array_keys', array_values(self::OPTIONS))), array_keys($addons)),
        ));
        if ($missing !== []) {
            $this->error('Sul server Esapanel mancano: '.implode(', ', $missing).'. Vanno creati in Esapanel con questi nomi esatti.');

            return self::FAILURE;
        }

        $this->line("Server Esapanel: {$server->name} (#{$server->id})");
        $group = $this->group();

        $paid = [];
        $position = 0;
        foreach (self::PRODUCTS as $slug => [$name, $plan, $monthly, $annually, $withOptions, $description]) {
            $product = $this->product($slug, $name, $description, $group, $currency, $plans[$plan], $monthly, $annually, ++$position);
            if ($withOptions) {
                $paid[] = $product;
            }
        }

        $this->addonOptions($paid, $currency);

        if ($this->changes === 0) {
            $this->info('Niente da cambiare.');
        } elseif ($this->write) {
            $this->info('Fatto.');
        } else {
            $this->line('Prova: nulla è stato scritto. Aggiungi --write per applicare.');
        }

        return self::SUCCESS;
    }

    /**
     * The plans by their Esapanel name, from the product form's list
     * ("WFP · EsaBackup100", "WFP · Reseller · EsaBackup Reseller 10 TB").
     *
     * @param  list<array{id: string, name: string}>  $packages
     * @return array<string, int>
     */
    private function planIds(array $packages): array
    {
        $plans = [];
        foreach ($packages as $package) {
            $parts = explode(' · ', $package['name']);
            $plans[(string) end($parts)] = (int) $package['id'];
        }

        return $plans;
    }

    private function group(): ?ProductGroup
    {
        $group = ProductGroup::where('slug', 'esabackup')->first();
        if ($group) {
            return $group;
        }

        $this->change('+ gruppo prodotti EsaBackup');

        return $this->write ? ProductGroup::create(['name' => 'EsaBackup', 'slug' => 'esabackup', 'hidden' => false, 'sort_order' => 6]) : null;
    }

    private function product(string $slug, string $name, string $description, ?ProductGroup $group, Currency $currency, int $planId, ?string $monthly, ?string $annually, int $position): ?Product
    {
        $visible = (bool) $this->option('visible');
        $free = $monthly === null;
        $product = Product::where('slug', $slug)->first();

        if (! $product) {
            $this->change("+ prodotto {$name} sul piano #{$planId}".($free ? ', gratuito' : ", {$monthly}/{$annually}").($visible ? '' : ' (nascosto)'));
            if (! $this->write) {
                return null;
            }

            $product = Product::create([
                'type' => 'other',
                'group_id' => $group?->id,
                'name' => $name,
                'slug' => $slug,
                'description' => $description,
                'hidden' => ! $visible,
                'show_domain_options' => false,
                'pay_type' => $free ? 'free' : 'recurring',
                'auto_setup' => $free ? 'order' : 'payment',
                'allow_upgrades' => true,
                'server_type' => EsapanelModule::NAME,
                'config_options' => ['package_name' => (string) $planId] + ($free ? ['trial_days' => self::TRIAL_DAYS] : []),
                'tax' => true,
                'sort_order' => $position,
            ]);
        }

        $config = (array) $product->config_options;
        if (($config['package_name'] ?? null) !== (string) $planId) {
            $this->change("  {$name}: piano #{$planId}");
            if ($this->write) {
                $product->update(['config_options' => array_merge($config, ['package_name' => (string) $planId])]);
            }
        }
        if ($visible && $product->hidden) {
            $this->change("  {$name}: in vendita");
            if ($this->write) {
                $product->update(['hidden' => false]);
            }
        }

        $this->price('product', $product->id, $currency, $free ? '-1.00' : $monthly, $free ? '-1.00' : $annually, $name);

        return $product;
    }

    /** @param  list<?Product>  $products */
    private function addonOptions(array $products, Currency $currency): void
    {
        $group = ConfigOptionGroup::where('name', self::OPTION_GROUP)->first();
        if (! $group) {
            $this->change('+ gruppo di opzioni '.self::OPTION_GROUP);
            if ($this->write) {
                $group = ConfigOptionGroup::create(['name' => self::OPTION_GROUP, 'description' => 'Spazio e account aggiuntivi']);
            }
        }

        foreach ($products as $product) {
            if ($group && $product && ! $product->configOptionGroups()->whereKey($group->id)->exists()) {
                $this->change("  opzioni collegate a {$product->name}");
                if ($this->write) {
                    $product->configOptionGroups()->attach($group->id);
                }
            }
        }

        $position = 0;
        foreach (self::OPTIONS as $label => $choices) {
            $optionName = 'addon|'.$label;
            $option = $group ? ConfigOption::where('group_id', $group->id)->where('option_name', $optionName)->first() : null;
            if (! $option) {
                $this->change("+ opzione {$label}");
                if (! $this->write) {
                    continue;
                }
                $option = ConfigOption::create(['group_id' => $group->id, 'option_name' => $optionName, 'option_type' => 'dropdown', 'sort_order' => ++$position, 'hidden' => false]);
            }

            $order = 0;
            foreach (['none' => ['Nessuno', '0.00', '0.00']] + $choices as $addon => [$choice, $monthly, $annually]) {
                // By the add-on it sells, not by its label.
                $sub = ConfigOptionSub::where('config_id', $option->id)
                    ->where('option_name', 'like', str_replace(['%', '_'], ['\\%', '\\_'], $addon).'|%')->first();
                if (! $sub) {
                    $this->change("  {$label}: + {$choice}");
                    if (! $this->write) {
                        continue;
                    }
                    $sub = ConfigOptionSub::create(['config_id' => $option->id, 'option_name' => $addon.'|'.$choice, 'sort_order' => $order, 'hidden' => false]);
                }
                $order++;
                $this->price(ConfigOptionSub::PRICING_TYPE, $sub->id, $currency, $monthly, $annually, "{$label} {$choice}");
            }
        }
    }

    /** Monthly and yearly price of a product or a choice; the other cycles are not sold. */
    private function price(string $type, int $id, Currency $currency, string $monthly, string $annually, string $what): void
    {
        $row = Pricing::where('type', $type)->where('rel_id', $id)->where('currency_id', $currency->id)->first();
        $money = fn ($value) => number_format((float) $value, 2, '.', '');

        if ($row && $money($row->monthly) === $monthly && $money($row->annually) === $annually) {
            return;
        }
        $this->change($row
            ? "  {$what}: prezzo {$money($row->monthly)}/{$money($row->annually)} -> {$monthly}/{$annually}"
            : "  {$what}: prezzo {$monthly}/{$annually}");
        if ($this->write) {
            Pricing::updateOrCreate(
                ['type' => $type, 'rel_id' => $id, 'currency_id' => $currency->id],
                ['monthly' => $monthly, 'annually' => $annually] + array_fill_keys(self::UNSOLD_CYCLES, -1),
            );
        }
    }

    private function change(string $line): void
    {
        $this->changes++;
        $this->line($line);
    }
}
