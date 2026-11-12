<?php

namespace Modules\Addons\Invoicetronic;

use App\Models\Invoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Addons\FatturaPA\FatturaPABuilder;
use RuntimeException;

/**
 * Sends issued invoices to the SDI through Invoicetronic, and reads back
 * what the SDI did with them.
 *
 * An invoice goes out on its own once it has waited the configured delay
 * after it was created, if it is dated on or after the start date. A file
 * Invoicetronic or the builder refuses waits for staff: they fix the
 * invoice, then send it again from the addon page.
 */
class SdiSender
{
    public const PROVIDER = 'invoicetronic';

    /** Invoices sent per run; the rest go out on the next one. */
    private const BATCH = 50;

    /** How long a sent invoice is asked after: the SDI answers within days. */
    private const FOLLOW_DAYS = 60;

    public function __construct(private FatturaPABuilder $builder, private ?InvoicetronicClient $client = null) {}

    /**
     * One scheduled pass: the invoices due go out, then the outcomes come in.
     *
     * @return array{sent: int, errors: int, updated: int}
     */
    public function run(): array
    {
        return $this->sendDue() + ['updated' => $this->refresh()];
    }

    /**
     * Sends the invoices that are due, and retries the posts that did not
     * get through last time.
     *
     * @return array{sent: int, errors: int}
     */
    public function sendDue(): array
    {
        $counts = ['sent' => 0, 'errors' => 0];
        $from = InvoicetronicSettings::sendFrom();

        if (InvoicetronicSettings::resolve()['auto_send'] !== '1' || $from === null) {
            return $counts;
        }

        $retries = SdiSubmission::where('state', SdiSubmission::SENDING)->orderBy('id')->limit(self::BATCH)->get();
        $due = Invoice::query()
            ->where(fn ($q) => $q->whereNull('type')->orWhere('type', '!=', 'proforma'))
            ->whereNotIn('status', ['draft', 'cancelled'])
            ->whereNotNull('invoice_num')->where('invoice_num', '!=', '')
            ->where('total', '!=', 0)
            ->whereDate('date', '>=', $from->toDateString())
            ->where('created_at', '<=', now()->subMinutes(InvoicetronicSettings::delayMinutes()))
            ->whereNotIn('id', SdiSubmission::select('invoice_id'))
            ->orderBy('date')->orderBy('id')
            ->limit(self::BATCH)
            ->get();

        try {
            foreach ($retries as $submission) {
                $this->tally($counts, $this->deliver($submission));
            }
            foreach ($due as $invoice) {
                try {
                    $submission = $this->open($invoice, 'auto');
                } catch (RuntimeException) {
                    continue; // Staff sent it a moment ago.
                }
                $this->tally($counts, $this->deliver($submission));
            }
        } catch (InvoicetronicUnavailable $e) {
            // Nothing more gets through this run; the rows wait as "sending".
            Log::warning('SDI sending paused: '.$e->getMessage());
        }

        return $counts;
    }

    /**
     * Sends one invoice now, at staff's request: the first time, or again
     * after the SDI or Invoicetronic refused it.
     *
     * @throws RuntimeException when the invoice cannot be sent (a proforma,
     *                          already on its way or delivered)
     * @throws InvoicetronicUnavailable when Invoicetronic cannot be reached;
     *                                  the attempt stays and is retried
     */
    public function send(Invoice $invoice, string $by): SdiSubmission
    {
        if ($invoice->isProforma()) {
            throw new RuntimeException(__('messages.invoicetronic.not_a_vat_invoice', ['number' => $invoice->invoice_num]));
        }

        return $this->deliver($this->open($invoice, $by));
    }

    /**
     * Reads the SDI's outcome for every invoice still on its way.
     *
     * @return int how many changed state
     */
    public function refresh(): int
    {
        $updated = 0;
        $client = $this->client();

        SdiSubmission::query()
            ->whereNotNull('external_id')
            ->whereNotIn('state', [...SdiSubmission::FINAL, SdiSubmission::ERROR, SdiSubmission::SENDING])
            ->where('sent_at', '>=', now()->subDays(self::FOLLOW_DAYS))
            ->orderBy('id')
            ->chunkById(100, function ($submissions) use ($client, &$updated) {
                $remote = $client->sent($submissions->pluck('external_id')->all());

                foreach ($submissions as $submission) {
                    $row = $remote[$submission->external_id] ?? null;
                    $changes = ['checked_at' => now()];

                    if ($row !== null && $row['state'] !== null && $row['state'] !== $submission->state) {
                        $changes += ['state' => $row['state'], 'state_at' => now(), 'sdi_identifier' => $row['identifier'] ?? $submission->sdi_identifier];
                        if (in_array($row['state'], [...SdiSubmission::NEEDS_ACTION, SdiSubmission::UNDELIVERABLE], true)) {
                            $changes['message'] = $client->latestNote($submission->external_id);
                        }
                        $updated++;
                    }

                    $submission->update($changes);
                }
            });

        return $updated;
    }

    /**
     * The new attempt for an invoice, refused while an earlier one is on its
     * way or done: an invoice reaches the SDI once. Locked on the invoice so
     * a click and the scheduled run cannot both open one.
     */
    private function open(Invoice $invoice, string $by): SdiSubmission
    {
        return DB::transaction(function () use ($invoice, $by) {
            Invoice::whereKey($invoice->id)->lockForUpdate()->first();
            $last = SdiSubmission::where('invoice_id', $invoice->id)->latest('id')->first();

            if ($last !== null && ! in_array($last->state, SdiSubmission::NEEDS_ACTION, true)) {
                throw new RuntimeException(__('messages.invoicetronic.already_sent', ['number' => $invoice->invoice_num]));
            }

            return SdiSubmission::create([
                'invoice_id' => $invoice->id,
                'provider' => self::PROVIDER,
                'state' => SdiSubmission::SENDING,
                'sent_by' => $by,
            ]);
        });
    }

    /**
     * Builds and posts the file for an attempt. A file that cannot be built
     * or that Invoicetronic refuses becomes an error for staff; when
     * Invoicetronic cannot be reached the attempt stays "sending" and the
     * exception goes up.
     *
     * @throws InvoicetronicUnavailable
     */
    private function deliver(SdiSubmission $submission): SdiSubmission
    {
        $invoice = Invoice::with('items')->find($submission->invoice_id);

        try {
            if ($invoice === null) {
                throw new RuntimeException(__('messages.invoicetronic.invoice_gone'));
            }
            $sent = $this->client()->send($this->builder->build($invoice), $this->idempotencyKey($submission));
        } catch (InvoicetronicUnavailable $e) {
            throw $e;
        } catch (RuntimeException $e) {
            $submission->update(['state' => SdiSubmission::ERROR, 'message' => $e->getMessage(), 'state_at' => now()]);

            return $submission;
        }

        $submission->update([
            'external_id' => $sent['id'],
            'file_name' => $sent['file_name'],
            'sdi_identifier' => $sent['identifier'],
            'state' => $sent['state'] ?? SdiSubmission::SUBMITTED,
            'message' => null,
            'sent_at' => now(),
            'state_at' => now(),
        ]);

        return $submission;
    }

    /**
     * Unique to this site and attempt, so a retried post is recognised by
     * Invoicetronic, and two sites sharing a test key never collide.
     */
    private function idempotencyKey(SdiSubmission $submission): string
    {
        return 'pnlcs-'.substr(sha1((string) config('app.url')), 0, 10).'-sdi-'.$submission->id;
    }

    /** @param array{sent: int, errors: int} $counts */
    private function tally(array &$counts, SdiSubmission $submission): void
    {
        $submission->state === SdiSubmission::ERROR ? $counts['errors']++ : $counts['sent']++;
    }

    private function client(): InvoicetronicClient
    {
        return $this->client ??= new InvoicetronicClient(InvoicetronicSettings::resolve()['api_key']);
    }
}
