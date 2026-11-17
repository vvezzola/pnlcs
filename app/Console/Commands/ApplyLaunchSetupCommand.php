<?php

namespace App\Console\Commands;

use App\Models\Currency;
use App\Models\DomainPricing;
use App\Models\Pricing;
use App\Models\Product;
use App\Models\RegistrarSettings;
use App\Models\Setting;
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
