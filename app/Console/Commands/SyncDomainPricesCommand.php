<?php

namespace App\Console\Commands;

use App\Models\RegistrarSettings;
use App\Services\Module\ModuleRegistry;
use Illuminate\Console\Command;

/**
 * The registrars screen's "Sync prices", every day, for each registrar whose
 * module can pull its prices and whose operator switched on the daily sync.
 * A registrar without that switch keeps its prices until someone presses the
 * button: a price list that moves on its own is a choice, not a default.
 */
class SyncDomainPricesCommand extends Command
{
    protected $signature = 'pnlcs:sync-domain-prices
        {--registrar= : Sync this registrar only, even without the daily switch}';

    protected $description = 'Pull the domain purchase prices, with the margin, from the registrars set to sync daily';

    public function handle(ModuleRegistry $registry): int
    {
        $only = $this->option('registrar');
        $names = $only ? [strtolower((string) $only)] : $registry->getRegistrarModules();
        $failed = false;

        foreach ($names as $name) {
            $module = $registry->getRegistrarModule($name);
            if (! $module || ! method_exists($module, 'syncPrices')) {
                if ($only) {
                    $this->error("{$name}: this registrar cannot sync its prices.");

                    return self::FAILURE;
                }

                continue;
            }

            $daily = RegistrarSettings::where('registrar', $name)->where('setting', 'daily_price_sync')->value('value') === '1';
            if (! $only && ! $daily) {
                continue;
            }

            $result = $module->syncPrices();
            if (empty($result['success'])) {
                $failed = true;
                $this->error("{$module->getModuleName()}: ".($result['message'] ?? 'sync failed'));

                continue;
            }

            $this->info("{$module->getModuleName()}: {$result['updated']} updated, {$result['unchanged']} unchanged, {$result['skipped']} skipped");
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
