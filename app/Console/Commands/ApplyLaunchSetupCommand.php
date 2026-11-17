<?php

namespace App\Console\Commands;

use App\Models\Currency;
use App\Models\DomainPricing;
use App\Models\Pricing;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\RegistrarSettings;
use App\Models\Setting;
use App\Models\SslModuleSettings;
use App\Services\Module\ModuleRegistry;
use App\Support\MapsTldCategories;
use App\Support\MapsTldGracePeriods;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Esalogica only: the settings and the 2027 hosting prices my.esalogica.com
 * needs before it is announced. Shows every change first; writes only with
 * --write, and running it again changes nothing.
 */
class ApplyLaunchSetupCommand extends Command
{
    use MapsTldCategories;
    use MapsTldGracePeriods;

    protected $signature = 'esalogica:apply-launch-setup
        {--write : Write to the database; without it nothing is changed}';

    protected $description = 'Esalogica: launch settings and 2027 hosting prices (dry run unless --write)';

    /** @var array<string, string> */
    private const SETTINGS = [
        'TOSUrl' => 'https://my.esalogica.com/legal/terms',
        'PrivacyUrl' => 'https://my.esalogica.com/legal/privacy',
        'LegalDocumentsRevised' => '2026-11-17',
        'CheckoutClauseApproval' => '1',
        'CheckoutImmediateStart' => '1',
        'KnowledgeBaseEnabled' => '0',
    ];

    /**
     * The 2027 hosting price list (EUR, VAT excluded), keyed by the slug the
     * product has now, with the slug it should have.
     *
     * @var array<string, array{slug: string, monthly: string, annually: string}>
     */
    private const PRODUCTS = [
        'hosting-start5' => ['slug' => 'hosting-start5', 'monthly' => '6.00', 'annually' => '72.00'],
        'business20' => ['slug' => 'business20', 'monthly' => '10.00', 'annually' => '120.00'],
        'business20-copy' => ['slug' => 'pro50', 'monthly' => '20.00', 'annually' => '240.00'],
        'pro50-copy' => ['slug' => 'premium100', 'monthly' => '35.00', 'annually' => '420.00'],
    ];

    /**
     * The extensions on sale, all through Openprovider, in the order the
     * price list shows them. The European ccTLDs that need a local address,
     * local documents or a manual process at the registry are left out, as
     * are .ru and .by.
     *
     * @var list<string>
     */
    private const TLDS = [
        '.it', '.com', '.eu', '.net', '.org', '.info', '.biz', '.shop', '.online', '.store', '.cloud', '.pro',
        '.at', '.be', '.bg', '.ch', '.cy', '.cz', '.de', '.dk', '.ee', '.es', '.fi', '.fr', '.gr', '.is',
        '.li', '.lt', '.lu', '.lv', '.me', '.mt', '.nl', '.pl', '.pt', '.ro', '.se', '.si',
        '.uk', '.co.uk', '.org.uk', '.me.uk',
        '.ad', '.al', '.ax', '.fo', '.gg', '.gl', '.im', '.je', '.sm',
        '.in', '.co.in', '.net.in', '.org.in', '.firm.in', '.gen.in', '.ind.in',
    ];

    /** @var list<string> */
    private const POPULAR_TLDS = ['.it', '.com', '.eu', '.net', '.org', '.info'];

    /** @var array<string, string> */
    private const OPENPROVIDER = ['margin_percent' => '50', 'round_up' => '1', 'daily_price_sync' => '1'];

    /**
     * The SSL certificates on sale, bought through SSL2BUY (code), one year at
     * a time, at Esalogica's own prices (EUR, VAT excluded).
     *
     * @var array<string, array{name: string, code: int, annually: string, description: string}>
     */
    private const SSL_PRODUCTS = [
        'ssl-positivessl' => [
            'name' => 'Certificato SSL PositiveSSL',
            'code' => 351,
            'annually' => '40.00',
            'description' => 'Certificato SSL di Sectigo (PositiveSSL) per un dominio, con e senza www. Convalida del dominio via email, DNS o file; emesso di solito in pochi minuti.',
        ],
        'ssl-alphassl' => [
            'name' => 'Certificato SSL AlphaSSL',
            'code' => 101,
            'annually' => '50.00',
            'description' => 'Certificato SSL di GlobalSign (AlphaSSL) per un dominio, con e senza www. Convalida del dominio via email, DNS o file; emesso di solito in pochi minuti.',
        ],
        'ssl-alphassl-wildcard' => [
            'name' => 'Certificato SSL Wildcard AlphaSSL',
            'code' => 102,
            'annually' => '120.00',
            'description' => 'Certificato SSL wildcard di GlobalSign (AlphaSSL) per un dominio e tutti i suoi sottodomini (*.tuodominio.it). Convalida del dominio via email o DNS.',
        ],
    ];

    /** @var list<string> */
    private const UNSOLD_CYCLES = ['quarterly', 'semiannually', 'biennially', 'triennially'];

    public function handle(): int
    {
        if (! Schema::hasColumn('orders', 'terms_declarations')) {
            $this->error('Lancia prima: php artisan migrate --force');

            return self::FAILURE;
        }

        $currency = Currency::where('code', 'EUR')->first();
        if (! $currency) {
            $this->error('Nessuna valuta EUR.');

            return self::FAILURE;
        }

        $write = (bool) $this->option('write');
        $changes = 0;

        DB::transaction(function () use ($currency, $write, &$changes): void {
            $this->line('Impostazioni');
            foreach (self::SETTINGS as $key => $value) {
                $current = (string) Setting::get($key, '');
                if ($current === $value) {
                    $this->line("  = {$key}: {$value}");

                    continue;
                }
                $changes++;
                $this->line("  * {$key}: '{$current}' -> '{$value}'");
                if ($write) {
                    Setting::set($key, $value);
                }
            }

            $this->line('Prodotti');
            foreach (self::PRODUCTS as $oldSlug => $target) {
                $product = Product::where('slug', $oldSlug)->first()
                    ?? Product::where('slug', $target['slug'])->first();
                if (! $product) {
                    $this->warn("  ! nessun prodotto con slug {$oldSlug} o {$target['slug']}: saltato");

                    continue;
                }
                $this->line("  {$product->name} (#{$product->id})");

                if ($product->slug !== $target['slug']) {
                    if (Product::where('slug', $target['slug'])->whereKeyNot($product->id)->exists()) {
                        $this->warn("    ! slug {$target['slug']} già usato da un altro prodotto: non rinominato");
                    } else {
                        $changes++;
                        $this->line("    * slug: {$product->slug} -> {$target['slug']}");
                        if ($write) {
                            $product->update(['slug' => $target['slug']]);
                        }
                    }
                }

                $pricing = Pricing::where('type', 'product')->where('rel_id', $product->id)
                    ->where('currency_id', $currency->id)->first();
                $wanted = ['monthly' => $target['monthly'], 'annually' => $target['annually']]
                    + array_fill_keys(self::UNSOLD_CYCLES, '-1.00');
                $updates = [];
                foreach ($wanted as $cycle => $price) {
                    $current = $pricing ? number_format((float) $pricing->{$cycle}, 2, '.', '') : '(nessuno)';
                    if ($current === $price) {
                        continue;
                    }
                    $updates[$cycle] = $price;
                    $this->line("    * {$cycle}: {$current} -> {$price}");
                }
                if ($updates === []) {
                    $this->line('    = prezzi già a listino');

                    continue;
                }
                $changes++;
                if ($write) {
                    Pricing::updateOrCreate(
                        ['type' => 'product', 'rel_id' => $product->id, 'currency_id' => $currency->id],
                        $updates,
                    );
                }
            }

            $changes += $this->openproviderSettings($write);
            $changes += $this->domainList($write);
            $changes += $this->sslProducts($currency, $write);
        });

        // Every --write, so a run after fixing the credentials still prices
        // and puts on sale what the first one could not.
        if ($write) {
            $this->syncDomainPrices();
        }

        if ($changes === 0) {
            $this->info('Niente da cambiare.');
        } elseif ($write) {
            $this->info('Fatto.');
        } else {
            $this->line('Prova: nulla è stato scritto. Aggiungi --write per applicare.');
        }

        return self::SUCCESS;
    }

    /** The margin, the rounding and the daily sync; the credentials are the operator's. */
    private function openproviderSettings(bool $write): int
    {
        $this->line('Openprovider');
        $stored = RegistrarSettings::where('registrar', 'openprovider')->pluck('value', 'setting');
        if (($stored['username'] ?? '') === '' || ($stored['test_mode'] ?? '1') !== '0') {
            $this->warn('  ! credenziali assenti o Sandbox Mode attivo: i prezzi non si sincronizzeranno');
        }

        $changes = 0;
        foreach (self::OPENPROVIDER as $key => $value) {
            $current = (string) ($stored[$key] ?? '');
            if ($current === $value) {
                $this->line("  = {$key}: {$value}");

                continue;
            }
            $changes++;
            $this->line("  * {$key}: '{$current}' -> '{$value}'");
            if ($write) {
                RegistrarSettings::updateOrCreate(['registrar' => 'openprovider', 'setting' => $key], ['value' => $value]);
            }
        }

        return $changes;
    }

    /**
     * The extensions on sale go to Openprovider; the rest come off sale
     * (kept, not deleted). An extension newly handed to Openprovider starts
     * from zero and off sale, so no old price survives the sync and nothing
     * is sold for nothing: it goes on sale once the sync has priced it.
     */
    private function domainList(bool $write): int
    {
        $this->line('Domini');
        $changes = 0;

        foreach (self::TLDS as $position => $extension) {
            $tld = DomainPricing::where('extension', $extension)->first();
            $wanted = [
                'auto_registrar' => 'openprovider',
                'is_popular' => in_array($extension, self::POPULAR_TLDS, true),
                'sort_order' => $position + 1,
            ];

            if (! $tld) {
                $changes++;
                $this->line("  + {$extension}: nuova, Openprovider");
                if ($write) {
                    [$grace, $redemption] = $this->tldGracePeriod(ltrim($extension, '.'));
                    DomainPricing::create($wanted + [
                        'extension' => $extension,
                        'category' => $this->tldCategory(ltrim($extension, '.')),
                        'grace_period' => $grace,
                        'redemption_grace_period' => $redemption,
                        'register_price' => 0, 'renew_price' => 0, 'transfer_price' => 0, 'restore_price' => 0,
                        'dns_management' => true,
                        'enabled' => false,
                    ]);
                }

                continue;
            }

            $differs = array_filter($wanted, fn ($value, $column) => $tld->{$column} != $value, ARRAY_FILTER_USE_BOTH);
            if ($differs === []) {
                continue;
            }
            $changes++;
            $resetPrices = strtolower((string) $tld->auto_registrar) !== 'openprovider';
            $this->line("  * {$extension}: ".implode(', ', array_map(
                fn ($column) => $column.' '.var_export($tld->{$column}, true).' -> '.var_export($wanted[$column], true),
                array_keys($differs),
            )).($resetPrices ? ' (prezzi da Openprovider)' : ''));
            if ($write) {
                $tld->update($differs + ($resetPrices
                    ? ['register_price' => 0, 'renew_price' => 0, 'transfer_price' => 0, 'restore_price' => 0, 'enabled' => false]
                    : []));
            }
        }

        $offSale = DomainPricing::where('enabled', true)->whereNotIn('extension', self::TLDS)->orderBy('extension')->pluck('extension');
        if ($offSale->isNotEmpty()) {
            $changes++;
            $this->line('  - non più in vendita: '.$offSale->implode(' '));
            if ($write) {
                DomainPricing::whereIn('extension', $offSale)->update(['enabled' => false]);
            }
        }

        return $changes;
    }

    /**
     * The SSL group, its three products (annual, sold on payment through
     * SSL2BUY) and the SSL2BUY product map that tells the module which
     * certificate each product buys. An existing product keeps everything but
     * its price; the map keeps any other product's entry.
     */
    private function sslProducts(Currency $currency, bool $write): int
    {
        $this->line('Certificati SSL');
        $changes = 0;

        if (blank(SslModuleSettings::getSetting('ssl2buy', 'partner_email')) || blank(SslModuleSettings::getSetting('ssl2buy', 'api_key'))) {
            $this->warn('  ! SSL2BUY senza credenziali: i certificati ordinati non potranno essere acquistati');
        } elseif (SslModuleSettings::getSetting('ssl2buy', 'test_mode', '0') === '1') {
            $this->warn('  ! SSL2BUY in modalità di prova');
        }

        // The group the operator made in the panel ("ssl"), or the one this
        // command made before; created only when there is neither.
        $group = ProductGroup::whereIn('slug', ['ssl', 'certificati-ssl'])->orderByRaw("slug = 'ssl' desc")->first();
        if ($group) {
            $this->line("  = gruppo: {$group->name}");
        } else {
            $changes++;
            $this->line('  + gruppo: Certificati SSL');
            if ($write) {
                $group = ProductGroup::create([
                    'name' => 'Certificati SSL',
                    'slug' => 'certificati-ssl',
                    'headline' => 'Certificati SSL',
                    'tagline' => 'Certificati a pagamento di GlobalSign e Sectigo, per chi ne ha bisogno oltre al Let\'s Encrypt incluso nell\'hosting.',
                    'hidden' => false,
                    'sort_order' => (int) ProductGroup::max('sort_order') + 1,
                ]);
            }
        }

        $map = [];
        foreach (preg_split('/[,;\n]+/', (string) SslModuleSettings::getSetting('ssl2buy', 'product_map', '')) as $pair) {
            if (preg_match('/^\s*(\d+)\s*[=:]\s*(\d+)\s*$/', $pair, $m)) {
                $map[(int) $m[1]] = (int) $m[2];
            }
        }
        $mapBefore = $map;

        foreach (self::SSL_PRODUCTS as $slug => $wanted) {
            $product = Product::where('slug', $slug)->first();
            $prices = ['annually' => $wanted['annually'], 'monthly' => '-1.00'] + array_fill_keys(self::UNSOLD_CYCLES, '-1.00');

            if (! $product) {
                $changes++;
                $this->line("  + {$wanted['name']}: {$wanted['annually']} €/anno, SSL2BUY {$wanted['code']}");
                if (! $write) {
                    continue;
                }
                $product = Product::create([
                    'type' => 'ssl',
                    'group_id' => $group->id,
                    'name' => $wanted['name'],
                    'slug' => $slug,
                    'description' => $wanted['description'],
                    'hidden' => false,
                    'show_domain_options' => false,
                    'pay_type' => 'recurring',
                    'auto_setup' => 'payment',
                    'ssl_module' => 'ssl2buy',
                    'tax' => true,
                    'sort_order' => array_search($slug, array_keys(self::SSL_PRODUCTS), true) + 1,
                ]);
                Pricing::create(['type' => 'product', 'rel_id' => $product->id, 'currency_id' => $currency->id] + $prices);
            } else {
                $pricing = Pricing::where('type', 'product')->where('rel_id', $product->id)->where('currency_id', $currency->id)->first();
                if (! $pricing || number_format((float) $pricing->annually, 2, '.', '') !== $wanted['annually']) {
                    $changes++;
                    $this->line("  * {$wanted['name']}: annually ".($pricing ? number_format((float) $pricing->annually, 2, '.', '') : '(nessuno)')." -> {$wanted['annually']}");
                    if ($write) {
                        Pricing::updateOrCreate(['type' => 'product', 'rel_id' => $product->id, 'currency_id' => $currency->id], $prices);
                    }
                }
            }

            $map[$product->id] = $wanted['code'];
        }

        if ($map != $mapBefore) {
            $changes++;
            ksort($map);
            $value = implode(', ', array_map(fn ($id, $code) => "{$id}={$code}", array_keys($map), $map));
            $this->line("  * SSL2BUY product_map: {$value}");
            if ($write) {
                SslModuleSettings::setSetting('ssl2buy', 'product_map', $value);
            }
        }

        return $changes;
    }

    private function syncDomainPrices(): void
    {
        $module = app(ModuleRegistry::class)->getRegistrarModule('openprovider');
        $result = $module && method_exists($module, 'syncPrices')
            ? $module->syncPrices()
            : ['success' => false, 'message' => 'modulo Openprovider assente'];

        if (empty($result['success'])) {
            $this->warn('Prezzi domini non sincronizzati: '.($result['message'] ?? '').'. Premi "Sync prices" su Openprovider quando è a posto.');

            return;
        }
        $this->info("Prezzi domini da Openprovider: {$result['updated']} aggiornati, {$result['skipped']} senza prezzo.");

        $listed = DomainPricing::whereIn('extension', self::TLDS)->where('auto_registrar', 'openprovider');
        $onSale = (clone $listed)->where('register_price', '>', 0)->where('renew_price', '>', 0)->where('enabled', false)->pluck('extension');
        if ($onSale->isNotEmpty()) {
            DomainPricing::whereIn('extension', $onSale)->update(['enabled' => true]);
            $this->info('  in vendita: '.$onSale->implode(' '));
        }
        $unpriced = (clone $listed)->where(fn ($q) => $q->where('register_price', '<=', 0)->orWhere('renew_price', '<=', 0))->pluck('extension');
        if ($unpriced->isNotEmpty()) {
            $this->warn('  ! senza prezzo, restano fuori vendita: '.$unpriced->implode(' '));
        }
    }
}
