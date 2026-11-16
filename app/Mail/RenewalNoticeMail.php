<?php

namespace App\Mail;

use App\Mail\Concerns\LocalizesToRecipient;
use App\Models\Client;
use App\Models\Invoice;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * What the next billing day will invoice a customer for, sent ahead of it so
 * they can cancel what they no longer want (pnlcs:renewal-notice).
 */
class RenewalNoticeMail extends Mailable implements ShouldQueue
{
    use LocalizesToRecipient;
    use Queueable, SerializesModels;

    /**
     * @param  list<array{description: string, due: string, amount: float, domain?: bool}>  $lines
     * @param  string  $billingDay  Y-m-d, the day the invoice is raised
     */
    public function __construct(
        public Client $client,
        public array $lines,
        public string $billingDay,
    ) {
        $this->localizeTo($this->client);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('email.renewal_notice.subject', [
            'date' => Carbon::parse($this->billingDay)->format(date_fmt()),
            'company' => company_name(),
        ]));
    }

    public function content(): Content
    {
        $billingDay = Carbon::parse($this->billingDay);
        $taxMode = Invoice::currentTaxMode();

        return new Content(
            view: 'emails.renewal-notice',
            with: [
                'client' => $this->client,
                'lines' => $this->lines,
                'billingDate' => $billingDay->format(date_fmt()),
                'deadline' => $billingDay->copy()->subDay()->format(date_fmt()),
                'renewalList' => $this->plainList(),
                'total' => array_sum(array_column($this->lines, 'amount')),
                'beforeTax' => $taxMode === null,
                'companyName' => company_name(),
            ],
        );
    }

    /** The lines as plain text, one per row, for a template written as text. */
    private function plainList(): string
    {
        return implode("\n", array_map(fn (array $line) => sprintf(
            '%s  %s  %s',
            $line['due'],
            empty($line['domain']) ? $line['description'] : __('email.renewal_notice.domain', ['domain' => $line['description']]),
            money_fmt($line['amount']),
        ), $this->lines));
    }
}
