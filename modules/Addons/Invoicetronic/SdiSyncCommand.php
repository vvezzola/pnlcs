<?php

namespace Modules\Addons\Invoicetronic;

use Illuminate\Console\Command;
use Throwable;

/** Sends the invoices that are due to the SDI and reads back the outcomes. */
class SdiSyncCommand extends Command
{
    protected $signature = 'pnlcs:sdi-sync';

    protected $description = 'Send due invoices to the SDI through Invoicetronic and read the outcomes';

    public function handle(SdiSender $sender): int
    {
        if (! InvoicetronicSettings::configured()) {
            $this->line(__('messages.invoicetronic.not_configured'));

            return self::SUCCESS;
        }

        try {
            $counts = $sender->run();
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(__('messages.invoicetronic.synced', $counts));

        return self::SUCCESS;
    }
}
