<?php

namespace Modules\Addons\Pec;

use Illuminate\Console\Command;

class PecReceiptsCommand extends Command
{
    protected $signature = 'pnlcs:pec-receipts';

    protected $description = 'Read PEC receipts from the sending mailbox and update the sent messages';

    public function handle(PecReceipts $receipts): int
    {
        if (! PecSettings::configured()) {
            $this->info('PEC: mailbox not configured.');

            return self::SUCCESS;
        }

        try {
            $this->info('PEC: '.$receipts->refresh().' message(s) updated.');
        } catch (\Throwable $e) {
            $this->error('PEC: '.$e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
