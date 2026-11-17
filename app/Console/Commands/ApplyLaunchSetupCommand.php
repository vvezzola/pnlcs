<?php

namespace App\Console\Commands;

use App\Models\Currency;
use App\Models\Pricing;
use App\Models\Product;
use App\Models\Setting;
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
        });

        if ($changes === 0) {
            $this->info('Niente da cambiare.');
        } elseif ($write) {
            $this->info('Fatto.');
        } else {
            $this->line('Prova: nulla è stato scritto. Aggiungi --write per applicare.');
        }

        return self::SUCCESS;
    }
}
