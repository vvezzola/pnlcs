<?php

namespace App\Console\Commands;

use App\Models\SslOrder;
use App\Services\SslProvisioningService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Renews by itself a certificate the panel manages, a set number of days
 * before it ends, when its module is told to (SSL2BUY: auto_renew_days).
 *
 * The next certificate is a free reissue while the order runs on, and a new
 * order, which is paid for, once it does not; when a subscription of several
 * years has a further year to come from the provider, that is left to be
 * configured there rather than bought again. A new order is
 * placed only for a service that is active, set to renew and paid past
 * today, and never twice for the same certificate: a renewal that failed is
 * left for the administrator. A certificate whose key only the customer
 * holds is theirs to renew.
 *
 * Validation and installing follow from the status check
 * (pnlcs:ssl-status-poll), as for a renewal pressed by hand.
 */
class SslAutoRenewCommand extends Command
{
    protected $signature = 'pnlcs:ssl-auto-renew {--dry-run : Show what would be renewed without doing it}';

    protected $description = 'Renew or reissue SSL certificates the panel manages before they end';

    public function handle(SslProvisioningService $sslService): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $orders = SslOrder::with('service')
            ->where('status', 'Completed')
            ->whereNotNull('crt_expires')
            ->orderBy('crt_expires')
            ->get();

        foreach ($orders as $order) {
            $module = $sslService->getModuleForOrder($order);
            $days = $module && method_exists($module, 'autoRenewDays') ? $module->autoRenewDays() : null;
            if ($days === null || $order->crt_expires->gt(now()->addDays($days)->endOfDay())) {
                continue;
            }

            $label = "#{$order->id} {$order->domain} (ends {$order->crt_expires->toDateString()})";
            $skip = $this->reasonToSkip($order);
            if ($skip !== null) {
                $this->line("  [--] {$label}: {$skip}");

                continue;
            }

            // An imported order does not say what was bought until it is read.
            if (method_exists($module, 'knowsSubscription') && ! $module->knowsSubscription($order)) {
                $module->getCertificateStatus($order);
                $order->refresh();
            }

            $reissue = method_exists($module, 'reissueCoversNext') && $module->reissueCoversNext($order);
            if (! $reissue && method_exists($module, 'subscriptionContinues') && $module->subscriptionContinues($order)) {
                $this->line("  [--] {$label}: the next year of its subscription waits at the provider, to be configured there");

                continue;
            }
            $action = $reissue ? 'reissue' : 'renew';
            if ($dryRun) {
                $this->line("  [..] {$label}: would {$action}");

                continue;
            }

            // Remembered before the call: whatever happens, this certificate
            // is never bought twice by this command.
            $data = (array) ($order->config_data ?? []);
            $data['auto_renewed_for'] = $order->crt_expires->toDateString();
            $order->update(['config_data' => $data]);

            $result = $reissue ? $sslService->reissueCertificate($order, '') : $sslService->renewCertificate($order);
            $this->line(($result['success'] ? '  [OK] ' : '  [!!] ')."{$label}: {$action}: {$result['message']}");
            Log::info('SSL auto-renew', ['order' => $order->id, 'action' => $action, 'success' => $result['success'], 'message' => $result['message']]);
        }

        return self::SUCCESS;
    }

    private function reasonToSkip(SslOrder $order): ?string
    {
        if (($order->config_data['auto_renewed_for'] ?? null) === $order->crt_expires->toDateString()) {
            return 'already renewed once for this certificate';
        }
        if (! empty($order->csr) && empty($order->private_key)) {
            return 'the customer holds the key';
        }

        $service = $order->service;
        if (! $service || $service->status !== 'active' || ! $service->auto_renew) {
            return 'the SSL service is not active or not set to renew';
        }
        if (! $service->next_due_date || $service->next_due_date->lte(today())) {
            return 'the SSL service is not paid past today';
        }

        return null;
    }
}
