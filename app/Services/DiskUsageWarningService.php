<?php

namespace App\Services;

use App\Mail\DiskUsageWarningMail;
use App\Models\Service;
use App\Models\Setting;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Tells a customer that a service is running out of disk space, before it
 * runs out: at DiskUsageWarningPercent of the limit the panel reported (0 =
 * off), with a link to move to a larger plan.
 *
 * It reads the figures the usage polling stored (disk_usage and disk_limit,
 * in megabytes; a limit of 0 is unlimited), so it runs right after it. Each
 * crossing is told once: the warning is remembered on the service and
 * forgotten when the usage drops back under the threshold by a margin, so a
 * service hovering around it is not warned every hour.
 */
class DiskUsageWarningService
{
    public const SETTING = 'DiskUsageWarningPercent';

    /** Percentage points under the threshold at which a warning is forgotten. */
    public const MARGIN = 5;

    public function threshold(): int
    {
        return max(0, min(100, (int) Setting::get(self::SETTING, 0)));
    }

    /**
     * Warn every service that crossed the threshold since it was last told.
     *
     * @return int how many warnings were sent
     */
    public function run(): int
    {
        $percent = $this->threshold();
        if ($percent === 0) {
            return 0;
        }

        Service::whereNotNull('disk_warning_sent_at')
            ->where(fn ($query) => $query->where('disk_limit', '<=', 0)
                ->orWhereRaw('disk_usage * 100 < disk_limit * ?', [$percent - self::MARGIN]))
            ->update(['disk_warning_sent_at' => null]);

        $due = Service::with(['client', 'product'])
            ->where('status', 'active')
            ->whereNull('disk_warning_sent_at')
            ->where('disk_limit', '>', 0)
            ->whereRaw('disk_usage * 100 >= disk_limit * ?', [$percent])
            ->get();

        $sent = 0;
        foreach ($due as $service) {
            $address = $service->client?->email;
            if (! $address) {
                continue;
            }

            try {
                Mail::to($address)->queue(new DiskUsageWarningMail($service));
                $service->forceFill(['disk_warning_sent_at' => now()])->save();
                $sent++;
            } catch (\Throwable $e) {
                Log::error("Disk usage warning failed for service #{$service->id}: {$e->getMessage()}");
            }
        }

        return $sent;
    }
}
