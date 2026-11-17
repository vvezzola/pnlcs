<?php

namespace App\Mail;

use App\Mail\Concerns\LocalizesToRecipient;
use App\Models\Service;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * A service is close to its disk space limit (DiskUsageWarningService): how
 * much it uses, what it includes, and where to move to a larger plan.
 */
class DiskUsageWarningMail extends Mailable implements ShouldQueue
{
    use LocalizesToRecipient;
    use Queueable, SerializesModels;

    public function __construct(public Service $service)
    {
        $this->localizeTo($this->service->client);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('email.disk_usage_warning.subject', [
            'domain' => $this->service->domain ?: ($this->service->product->name ?? ''),
            'percent' => $this->percent(),
            'company' => company_name(),
        ]));
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.disk-usage-warning',
            with: [
                'client' => $this->service->client,
                'service' => $this->service,
                'usagePercent' => $this->percent(),
                'diskUsed' => self::size((int) $this->service->disk_usage),
                'diskLimit' => self::size((int) $this->service->disk_limit),
                'upgradeUrl' => route('client.services.upgrade', $this->service),
                'companyName' => company_name(),
            ],
        );
    }

    private function percent(): int
    {
        $limit = (int) $this->service->disk_limit;

        return $limit > 0 ? (int) floor((int) $this->service->disk_usage * 100 / $limit) : 0;
    }

    /** Megabytes as people read them: "750 MB", "4.8 GB". */
    private static function size(int $megabytes): string
    {
        return $megabytes >= 1024
            ? rtrim(rtrim(number_format($megabytes / 1024, 1, '.', ''), '0'), '.').' GB'
            : $megabytes.' MB';
    }
}
