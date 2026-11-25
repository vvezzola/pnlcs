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
 * per plan in the group "esabackup", and one more per plan with the remote
 * copy (no free trial: the user's choice, it invites abuse). Each product
 * with add-ons has its own option group of priced "addon|..." options. Plans and add-ons are found on the
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
     * slug => [name and Esapanel plan, monthly (null: sold yearly only),
     * yearly, add-on keys, description]. Prices in EUR without VAT. Each
     * plan also has a "+ Copia remota" product, see COPY_FACTOR.
     *
     * @var array<string, array{0: string, 1: ?string, 2: string, 3: list<string>, 4: string}>
     */
    private const PLANS = [
        'esabackup-25' => ['EsaBackup25', null, '25.00', [],
            '25 GB per provare la copia fuori sede o per scambiare file grandi con i colleghi. 100 GB di traffico al mese, 3 account, 1 cartella condivisa, sorveglianza delle copie. Datacenter nell\'Unione Europea (Amsterdam).'],
        'esabackup-100' => ['EsaBackup100', null, '60.00', ['account', 'cartelle', 'traffico'],
            '100 GB per la copia fuori sede dei backup di NAS, server e PC. 200 GB di traffico al mese, 10 account, 3 cartelle condivise, sorveglianza delle copie e report. Datacenter nell\'Unione Europea (Amsterdam).'],
        'esabackup-500' => ['EsaBackup500', '24.00', '240.00', ['spazio', 'account', 'cartelle', 'traffico'],
            '500 GB per la copia fuori sede dei backup di NAS, server e PC. 1 TB di traffico al mese, 50 account, 10 cartelle condivise, sorveglianza delle copie e report. Datacenter nell\'Unione Europea (Amsterdam).'],
        'esabackup-1t' => ['EsaBackup1T', '45.00', '450.00', ['spazio', 'cartelle', 'traffico'],
            '1 TB per la copia fuori sede dei backup di NAS, server e PC. 2 TB di traffico al mese, account illimitati, 30 cartelle condivise, sorveglianza delle copie e report. Datacenter nell\'Unione Europea (Amsterdam).'],
        'esabackup-reseller-3tb' => ['EsaBackup Reseller 3 TB', '120.00', '1200.00', [],
            'Per rivendere EsaBackup con il proprio marchio e un indirizzo IP dedicato: 3 TB e 10 TB di traffico al mese da distribuire ai propri clienti, nel pannello Esapanel e dal proprio PNLCS.'],
        'esabackup-reseller-10tb' => ['EsaBackup Reseller 10 TB', '260.00', '2600.00', [],
            'Per rivendere EsaBackup con il proprio marchio e un indirizzo IP dedicato: 10 TB e 30 TB di traffico al mese da distribuire ai propri clienti, nel pannello Esapanel e dal proprio PNLCS.'],
    ];

    /**
     * The add-on options: key => [option label, costs more with the remote
     * copy, [Esapanel add-on => [choice label, monthly, yearly]]]. Every
     * option starts with "none".
     *
     * @var array<string, array{0: string, 1: bool, 2: array<string, array{0: string, 1: string, 2: string}>}>
     */
    private const ADDONS = [
        'spazio' => ['Spazio aggiuntivo', true, [
            'EsaBackup Spazio +100 GB' => ['+100 GB', '3.00', '30.00'],
            'EsaBackup Spazio +300 GB' => ['+300 GB', '6.00', '60.00'],
            'EsaBackup Spazio +500 GB' => ['+500 GB', '12.00', '120.00'],
        ]],
        'account' => ['Account aggiuntivi', false, [
            'EsaBackup Account +10' => ['+10 account', '3.00', '30.00'],
        ]],
        'cartelle' => ['Cartelle condivise aggiuntive', false, [
            'EsaBackup Cartelle +5' => ['+5 cartelle condivise', '3.00', '30.00'],
        ]],
        'traffico' => ['Traffico aggiuntivo', true, [
            'EsaBackup Traffico +1 TB' => ['+1 TB al mese', '5.00', '50.00'],
            'EsaBackup Traffico +5 TB' => ['+5 TB al mese', '20.00', '200.00'],
        ]],
    ];

    /**
     * Add-ons a plan does not offer, by plan slug (its remote copy product
     * too): +500 GB on 500 GB would make 1 TB for less than the 1 TB plan.
     *
     * @var array<string, list<string>>
     */
    private const NOT_OFFERED = [
        'esabackup-500' => ['EsaBackup Spazio +500 GB'],
    ];

    /**
     * The remote copy (a second copy on another Esapanel node) costs 70% more,
     * on the plan and on the space and traffic add-ons. Its products are on
     * the Esapanel plan named like the plan plus COPY_PLAN.
     */
    private const COPY_FACTOR = 1.7;

    private const COPY_PLAN = ' Copia remota';

    private const COPY_DESCRIPTION = ' Con la copia remota: una seconda copia dei dati in un altro datacenter dell\'Unione Europea.';

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

        $products = $this->products();
        $missing = array_values(array_unique(array_merge(
            array_diff(array_column($products, 'plan'), array_keys($plans)),
            array_diff(array_merge(...array_map(fn ($addon) => array_keys($addon[2]), array_values(self::ADDONS))), array_keys($addons)),
        )));
        if ($missing !== []) {
            $this->error('Sul server Esapanel mancano: '.implode(', ', $missing).'. Vanno creati in Esapanel con questi nomi esatti.');

            return self::FAILURE;
        }

        $this->line("Server Esapanel: {$server->name} (#{$server->id})");
        $group = $this->group();

        foreach ($products as $position => $item) {
            $product = $this->product($item, $group, $currency, $plans[$item['plan']], $position + 1);
            if ($item['addons'] !== []) {
                $this->addonOptions($product, $item, $currency);
            }
        }

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

    /** @param  array{slug: string, name: string, plan: string, monthly: string, annually: string, addons: list<string>, notOffered: list<string>, copy: bool, description: string}  $item */
    private function product(array $item, ?ProductGroup $group, Currency $currency, int $planId, int $position): ?Product
    {
        ['slug' => $slug, 'name' => $name, 'monthly' => $monthly, 'annually' => $annually, 'description' => $description] = $item;
        $visible = (bool) $this->option('visible');
        $product = Product::where('slug', $slug)->first();

        if (! $product) {
            $this->change("+ prodotto {$name} sul piano #{$planId}, {$monthly}/{$annually}".($visible ? '' : ' (nascosto)'));
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
                'pay_type' => 'recurring',
                'auto_setup' => 'payment',
                'allow_upgrades' => true,
                'server_type' => EsapanelModule::NAME,
                'config_options' => ['package_name' => (string) $planId],
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

        $this->price('product', $product->id, $currency, $monthly, $annually, $name);

        return $product;
    }

    /**
     * The products to make: each plan, then the same plan with the remote
     * copy. A plan sold yearly only has its monthly price at -1 (not sold),
     * and so have its add-ons.
     *
     * @return list<array{slug: string, name: string, plan: string, monthly: string, annually: string, addons: list<string>, notOffered: list<string>, copy: bool, description: string}>
     */
    private function products(): array
    {
        $products = [];
        foreach (self::PLANS as $slug => [$name, $monthly, $annually, $addons, $description]) {
            $notOffered = self::NOT_OFFERED[$slug] ?? [];
            $products[] = ['slug' => $slug, 'name' => $name, 'plan' => $name, 'monthly' => $monthly ?? '-1.00',
                'annually' => $annually, 'addons' => $addons, 'notOffered' => $notOffered, 'copy' => false, 'description' => $description];
            $products[] = ['slug' => $slug.'-copia', 'name' => $name.' + Copia remota', 'plan' => $name.self::COPY_PLAN,
                'monthly' => $monthly === null ? '-1.00' : $this->withCopy($monthly), 'annually' => $this->withCopy($annually),
                'addons' => $addons, 'notOffered' => $notOffered, 'copy' => true, 'description' => $description.self::COPY_DESCRIPTION];
        }

        return $products;
    }

    private function withCopy(string $price): string
    {
        return number_format(round((float) $price * self::COPY_FACTOR, 2), 2, '.', '');
    }

    /**
     * The product's own option group ("<product> extras"): its prices
     * depend on the product (yearly only, remote copy).
     *
     * @param  array{slug: string, name: string, plan: string, monthly: string, annually: string, addons: list<string>, notOffered: list<string>, copy: bool, description: string}  $item
     */
    private function addonOptions(?Product $product, array $item, Currency $currency): void
    {
        $groupName = $item['name'].' extras';
        $group = ConfigOptionGroup::where('name', $groupName)->first();
        if (! $group) {
            $this->change('+ gruppo di opzioni '.$groupName);
            if ($this->write) {
                $group = ConfigOptionGroup::create(['name' => $groupName, 'description' => 'Add-on di '.$item['name']]);
            }
        }

        if ($group && $product && ! $product->configOptionGroups()->whereKey($group->id)->exists()) {
            $this->change("  opzioni collegate a {$product->name}");
            if ($this->write) {
                $product->configOptionGroups()->attach($group->id);
            }
        }

        $position = 0;
        foreach ($item['addons'] as $key) {
            [$label, $costsMoreWithCopy, $choices] = self::ADDONS[$key];
            $optionName = 'addon|'.$label;
            $option = $group ? ConfigOption::where('group_id', $group->id)->where('option_name', $optionName)->first() : null;
            $position++;
            if (! $option) {
                $this->change("  {$item['name']}: + opzione {$label}");
                if (! $this->write) {
                    continue;
                }
                $option = ConfigOption::create(['group_id' => $group->id, 'option_name' => $optionName, 'option_type' => 'dropdown', 'sort_order' => $position, 'hidden' => false]);
            }

            $order = 0;
            $choices = array_diff_key($choices, array_flip($item['notOffered']));
            foreach (['none' => ['Nessuno', '0.00', '0.00']] + $choices as $addon => [$choice, $monthly, $annually]) {
                if ($item['copy'] && $costsMoreWithCopy) {
                    [$monthly, $annually] = [$this->withCopy($monthly), $this->withCopy($annually)];
                }
                if ($item['monthly'] === '-1.00') {
                    $monthly = '-1.00';
                }
                // By the add-on it sells, not by its label.
                $sub = ConfigOptionSub::where('config_id', $option->id)
                    ->where('option_name', 'like', str_replace(['%', '_'], ['\\%', '\\_'], $addon).'|%')->first();
                if (! $sub) {
                    $this->change("  {$item['name']}, {$label}: + {$choice}");
                    if (! $this->write) {
                        continue;
                    }
                    $sub = ConfigOptionSub::create(['config_id' => $option->id, 'option_name' => $addon.'|'.$choice, 'sort_order' => $order, 'hidden' => false]);
                }
                $order++;
                $this->price(ConfigOptionSub::PRICING_TYPE, $sub->id, $currency, $monthly, $annually, "{$item['name']}, {$label} {$choice}");
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
