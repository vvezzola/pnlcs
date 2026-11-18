<?php

namespace App\Console\Commands;

use App\Models\Currency;
use App\Models\ProductGroup;
use App\Models\Server;
use Illuminate\Console\Command;
use Modules\Servers\EsapanelLicense\EsapanelCatalog;
use Modules\Servers\EsapanelLicense\EsapanelLicenseModule;

/**
 * Esalogica only: the Esapanel licence products at Esalogica's 2027 prices.
 * The catalogue itself (levels, modules, the Trial) is the module's
 * EsapanelCatalog, the same a partner fills from Products > Esapanel
 * catalogue; this command gives it Esalogica's price list. Shows every
 * change first; writes only with --write, and running it again changes
 * nothing.
 */
class EsapanelProductsCommand extends Command
{
    protected $signature = 'esalogica:esapanel-products
        {--write : Write to the database; without it nothing is changed}
        {--visible : Put the products on sale; without it new ones stay hidden}';

    protected $description = 'Esalogica: Esapanel licence products of the 2027 price list (dry run unless --write)';

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

    public function handle(): int
    {
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

        $prices = [];
        foreach (self::PRICES as $module => $columns) {
            foreach ($columns as $i => [$monthly, $annually]) {
                $prices[$module][EsapanelCatalog::PRICE_COLUMNS[$i]] = ['monthly' => $monthly, 'annually' => $annually];
            }
        }

        $write = (bool) $this->option('write');
        $result = app(EsapanelCatalog::class)->sync($server, $group, $currency, $prices, (bool) $this->option('visible'), $write);

        foreach ($result['lines'] as $line) {
            $this->line($line);
        }
        if (! $result['ok']) {
            return self::FAILURE;
        }

        if ($result['changes'] === 0) {
            $this->info('Niente da cambiare.');
        } elseif ($write) {
            $this->info('Fatto.');
        } else {
            $this->line('Prova: nulla è stato scritto. Aggiungi --write per applicare.');
        }

        return self::SUCCESS;
    }
}
