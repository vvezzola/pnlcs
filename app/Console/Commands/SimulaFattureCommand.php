<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Services\InvoiceGenerationService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

/**
 * Esalogica only: what the billing run of a given day would invoice, without
 * invoicing. The real run (pnlcs:generate-invoices) is played with the clock
 * set to that day, inside a transaction that is rolled back: no invoice, no
 * number, no mail, no queued job, no call to anyone stays. The invoices it
 * would make are listed, and written to a CSV to compare with the real
 * invoicing.
 */
class SimulaFattureCommand extends Command
{
    protected $signature = 'esalogica:simula-fatture
        {--date= : The billing day to play (Y-m-d); default the next one}
        {--out= : Write the invoices and their lines to this CSV}';

    protected $description = 'Esalogica: list what a billing run would invoice, without invoicing anything';

    public function handle(InvoiceGenerationService $billing): int
    {
        $day = $this->option('date')
            ? Carbon::parse((string) $this->option('date'))
            : ($billing->nextBillingDay() ?? now());
        $day = $day->copy()->setTime(6, 0);

        // Nothing may leave the process while the run is played.
        Mail::fake();
        Queue::fake();
        Bus::fake();
        Notification::fake();
        Http::fake();
        Http::preventStrayRequests();

        Carbon::setTestNow($day);
        [$cutoff] = $billing->billingWindow($day);

        $invoices = [];
        DB::beginTransaction();
        try {
            $summary = $billing->generateDueInvoices();
            foreach (Invoice::with(['items', 'client'])->whereIn('id', $summary['invoice_ids'])->get() as $invoice) {
                $client = $invoice->client;
                $invoices[] = [
                    'client_id' => $invoice->client_id,
                    'cliente' => $client ? (trim((string) $client->company_name) ?: trim($client->first_name.' '.$client->last_name)) : '',
                    'data' => $invoice->date?->toDateString(),
                    'scadenza' => $invoice->due_date?->toDateString(),
                    'imponibile' => number_format((float) $invoice->subtotal, 2, '.', ''),
                    'iva' => number_format((float) $invoice->tax + (float) ($invoice->tax2 ?? 0), 2, '.', ''),
                    'totale' => number_format((float) $invoice->total, 2, '.', ''),
                    'righe' => $invoice->items->map(fn ($item) => [
                        'descrizione' => (string) $item->description,
                        'importo' => number_format((float) $item->amount, 2, '.', ''),
                        'scadenza_servizio' => $item->due_date ? Carbon::parse($item->due_date)->toDateString() : '',
                    ])->all(),
                ];
            }
        } finally {
            DB::rollBack();
            Carbon::setTestNow();
        }

        usort($invoices, fn ($a, $b) => strcmp($a['cliente'], $b['cliente']));
        $this->line("Fatturazione del {$day->toDateString()}: servizi e domini in scadenza fino al {$cutoff->toDateString()}.");
        foreach ($invoices as $invoice) {
            $this->line(sprintf('%-45s %3d righe  imponibile %9s  totale %9s  scade %s',
                mb_strimwidth($invoice['cliente'], 0, 45), count($invoice['righe']), $invoice['imponibile'], $invoice['totale'], $invoice['scadenza']));
        }
        $this->line(sprintf('%d fatture, imponibile %s, totale %s.%s',
            count($invoices),
            number_format(array_sum(array_column($invoices, 'imponibile')), 2, '.', ''),
            number_format(array_sum(array_column($invoices, 'totale')), 2, '.', ''),
            $summary['errors'] > 0 ? " {$summary['errors']} clienti in errore (vedi il log)." : ''));
        $this->line('Simulazione: nulla è stato scritto né inviato.');

        if ($out = $this->option('out')) {
            $f = fopen((string) $out, 'w');
            fputcsv($f, ['client_id', 'cliente', 'data', 'scadenza_fattura', 'imponibile', 'iva', 'totale', 'riga', 'importo_riga', 'scadenza_servizio'], escape: '\\');
            foreach ($invoices as $invoice) {
                foreach ($invoice['righe'] as $line) {
                    fputcsv($f, [$invoice['client_id'], $invoice['cliente'], $invoice['data'], $invoice['scadenza'], $invoice['imponibile'],
                        $invoice['iva'], $invoice['totale'], $line['descrizione'], $line['importo'], $line['scadenza_servizio']], escape: '\\');
                }
            }
            fclose($f);
            $this->line("Righe scritte in {$out}");
        }

        return self::SUCCESS;
    }
}
