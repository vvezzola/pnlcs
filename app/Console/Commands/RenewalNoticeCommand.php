<?php

namespace App\Console\Commands;

use App\Mail\RenewalNoticeMail;
use App\Models\Client;
use App\Models\Setting;
use App\Services\AddonService;
use App\Services\InvoiceGenerationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * The renewal notice: on RenewalNoticeDay each customer is sent the list of
 * what the next consolidated billing day will invoice them for, so they can
 * cancel what they no longer want before the invoice is raised and before
 * anything is renewed at a supplier.
 *
 * The list comes from the same query the billing run uses
 * (InvoiceGenerationService::upcomingRenewals), so what the customer is told
 * is what they are invoiced for, less whatever they cancel in between.
 */
class RenewalNoticeCommand extends Command
{
    protected $signature = 'pnlcs:renewal-notice
        {--dry-run : List what would be sent, on any day, without sending}
        {--force : Send even when today is not the notice day, or the notice already went out this month}';

    protected $description = 'Email each customer what the next billing day will invoice';

    /** The setting that remembers the month the notice last went out (Y-m). */
    public const SENT_SETTING = 'RenewalNoticeSentFor';

    public function handle(InvoiceGenerationService $billing, AddonService $addons): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');
        $noticeDay = (int) Setting::get('RenewalNoticeDay', 0);
        $billingDay = $billing->nextBillingDay();

        if ($billingDay === null) {
            $this->info('No consolidated billing day is set: there is nothing to give notice of.');

            return Command::SUCCESS;
        }

        if (! $dryRun && ! $force) {
            if ($noticeDay < 1 || now()->day !== $noticeDay) {
                return Command::SUCCESS;
            }

            if (Setting::get(self::SENT_SETTING) === now()->format('Y-m')) {
                $this->info('The renewal notice already went out this month.');

                return Command::SUCCESS;
            }
        }

        $items = $billing->upcomingRenewals($billingDay);
        $lines = [];

        foreach ($items['services'] as $service) {
            $lines[$service->client_id][] = [
                'description' => trim(($service->product->name ?? '').($service->domain ? ' — '.$service->domain : '')),
                'sort' => $service->next_due_date?->toDateString() ?? '',
                'due' => $service->next_due_date?->format(date_fmt()) ?? '',
                'amount' => (float) $service->amount,
            ];
        }

        foreach ($items['addons'] as $addon) {
            $line = $addons->lineItem($addon);
            $lines[$addon->client_id][] = [
                'description' => (string) $line['description'],
                'sort' => (string) ($line['due_date'] ?? ''),
                'due' => $addon->next_due_date?->format(date_fmt()) ?? '',
                'amount' => (float) $line['amount'],
            ];
        }

        foreach ($items['domains'] as $domain) {
            $lines[$domain->client_id][] = [
                // Worded in the mail, in the customer's language.
                'description' => (string) $domain->domain,
                'domain' => true,
                'sort' => $domain->next_due_date?->toDateString() ?? '',
                'due' => $domain->next_due_date?->format(date_fmt()) ?? '',
                'amount' => (float) $domain->recurring_amount,
            ];
        }

        $clients = Client::whereIn('id', array_keys($lines))->get()->keyBy('id');
        $sent = 0;

        foreach ($lines as $clientId => $clientLines) {
            $client = $clients->get($clientId);
            $address = $client?->billingEmail();

            if (! $client || ! $address) {
                continue;
            }

            usort($clientLines, fn ($a, $b) => [$a['sort'], $a['description']] <=> [$b['sort'], $b['description']]);
            $clientLines = array_map(fn ($line) => array_diff_key($line, ['sort' => true]), $clientLines);

            if ($dryRun) {
                $this->line(sprintf('%s <%s>: %d item(s), %s', $client->company_name ?: trim($client->first_name.' '.$client->last_name), $address, count($clientLines), money_fmt(array_sum(array_column($clientLines, 'amount')))));
                foreach ($clientLines as $line) {
                    $this->line("    {$line['due']}  {$line['description']}  ".money_fmt($line['amount']));
                }
                $sent++;

                continue;
            }

            try {
                Mail::to($address)->queue(new RenewalNoticeMail($client, $clientLines, $billingDay->toDateString()));
                $sent++;
            } catch (\Throwable $e) {
                Log::error("Renewal notice failed for client #{$client->id}: {$e->getMessage()}");
            }
        }

        if (! $dryRun) {
            Setting::set(self::SENT_SETTING, now()->format('Y-m'));
        }

        $this->info(($dryRun ? 'Would send' : 'Sent')." {$sent} renewal notice(s) for the billing day of {$billingDay->toDateString()}.");

        return Command::SUCCESS;
    }
}
