<?php

namespace Modules\Addons\BankReconciliation;

use Illuminate\Console\Command;
use Throwable;

/** Reads new incoming transfers from the bank and matches them to invoices. */
class BankSyncCommand extends Command
{
    protected $signature = 'pnlcs:bank-sync';

    protected $description = 'Read incoming bank transfers and match them to open invoices';

    public function handle(BankReconciler $reconciler): int
    {
        if (! BankReconciliationSettings::configured()) {
            $this->line(__('messages.bank_reconciliation.not_configured'));

            return self::SUCCESS;
        }

        try {
            $counts = $reconciler->sync();
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(__('messages.bank_reconciliation.synced', $counts));

        return self::SUCCESS;
    }
}
