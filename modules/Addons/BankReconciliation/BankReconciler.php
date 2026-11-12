<?php

namespace Modules\Addons\BankReconciliation;

use App\Models\ActivityLog;
use App\Models\Invoice;
use App\Models\Setting;
use App\Services\PaymentService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Turns incoming bank transfers into invoice payments.
 *
 * A transfer is paid onto invoices only when it can be told for certain
 * what it pays: its text names one or more open invoices - by number
 * (ESA270001) or by the payment reference PNLCS prints for bank transfers
 * (payment_ref(), INV1234) - and the amount is exactly what those invoices
 * still owe. Everything else waits for staff with the invoices it most
 * likely pays: a partial payment, an amount that does not add up, a
 * transfer with no reference at all. With automatic payment off, even a
 * certain match waits, so the matching can be watched before it is trusted.
 */
class BankReconciler
{
    /** Invoices that can still be paid (PaymentService::PAYABLE_STATUSES, less draft). */
    public const OPEN_STATUSES = ['unpaid', 'overdue', 'payment_pending', 'partially_paid', 'collections'];

    public const GATEWAY = 'banktransfer';

    private const TOLERANCE = 0.005;

    public function __construct(private PaymentService $payments) {}

    /**
     * Reads the account and processes every transfer not seen before.
     *
     * @return array{fetched: int, new: int, paid: int, review: int}
     */
    public function sync(?QontoClient $client = null): array
    {
        $settings = BankReconciliationSettings::resolve();
        if (! BankReconciliationSettings::configured()) {
            throw new RuntimeException(__('messages.bank_reconciliation.not_configured'));
        }
        $client ??= new QontoClient($settings);

        // From the last transfer already read (a day back, in case one settled
        // late), or the look-back window on the first run.
        $last = BankTransaction::where('source', 'qonto')->max('booked_at');
        $since = $last ? now()->parse($last)->subDay() : now()->subDays(max(1, (int) $settings['lookback_days']));

        $counts = ['fetched' => 0, 'new' => 0, 'paid' => 0, 'review' => 0];
        foreach ($client->incomingSince($settings['iban'], $since) as $row) {
            $counts['fetched']++;
            if ($row['id'] === '' || $row['amount'] <= 0) {
                continue;
            }

            $transaction = BankTransaction::firstOrCreate(
                ['source' => 'qonto', 'external_id' => $row['id']],
                [
                    'booked_at' => $row['booked_at'] ? now()->parse($row['booked_at']) : now(),
                    'amount' => $row['amount'],
                    'currency' => $row['currency'],
                    'counterparty' => $row['counterparty'],
                    'counterparty_iban' => $row['counterparty_iban'],
                    'reference' => $row['reference'],
                    'status' => BankTransaction::REVIEW,
                ],
            );
            if (! $transaction->wasRecentlyCreated) {
                continue;
            }

            $counts['new']++;
            $this->process($transaction, $settings['auto_apply'] === '1');
            $transaction->status === BankTransaction::MATCHED ? $counts['paid']++ : $counts['review']++;
        }

        return $counts;
    }

    /** Decides what a new transfer pays, and pays it when that is certain and allowed. */
    public function process(BankTransaction $transaction, bool $autoApply): void
    {
        $named = $this->invoicesNamedIn((string) $transaction->reference);
        $open = $named->filter(fn (Invoice $invoice) => in_array(strtolower((string) $invoice->status), self::OPEN_STATUSES, true))->values();
        $owed = round($open->sum(fn (Invoice $invoice) => $this->payments->balance($invoice)), 2);

        if ($named->isNotEmpty() && $open->isEmpty()) {
            $this->review($transaction, BankTransaction::REASON_NOT_OPEN, $named->pluck('id')->all());

            return;
        }

        if ($open->isEmpty()) {
            $this->review($transaction, BankTransaction::REASON_NO_REFERENCE, $this->invoicesOwing((float) $transaction->amount)->pluck('id')->all());

            return;
        }

        if (abs((float) $transaction->amount - $owed) >= self::TOLERANCE) {
            $this->review($transaction, BankTransaction::REASON_AMOUNT, $open->pluck('id')->all());

            return;
        }

        if (! $autoApply) {
            $this->review($transaction, BankTransaction::REASON_READY, $open->pluck('id')->all());

            return;
        }

        $this->pay($transaction, $open, 'auto');
    }

    /**
     * Staff confirm what a transfer pays. The amount goes to the invoices in
     * the order given, each up to what it owes; what is left over goes to the
     * last one, where PaymentService keeps an overpayment as credit.
     *
     * @param  list<int>  $invoiceIds
     * @return array{success: bool, message: string}
     */
    public function confirm(BankTransaction $transaction, array $invoiceIds, string $by): array
    {
        if ($transaction->status === BankTransaction::MATCHED) {
            return ['success' => false, 'message' => __('messages.bank_reconciliation.already_matched')];
        }

        $invoices = Invoice::whereIn('id', $invoiceIds)->get()->sortBy(fn (Invoice $invoice) => array_search($invoice->id, $invoiceIds, true))->values();
        if ($invoices->isEmpty()) {
            return ['success' => false, 'message' => __('messages.bank_reconciliation.choose_invoice')];
        }

        $this->pay($transaction, $invoices, $by);

        return ['success' => true, 'message' => __('messages.bank_reconciliation.paid', ['amount' => number_format((float) $transaction->amount, 2, ',', '.')])];
    }

    public function ignore(BankTransaction $transaction, string $by): void
    {
        if ($transaction->status !== BankTransaction::MATCHED) {
            $transaction->forceFill(['status' => BankTransaction::IGNORED, 'matched_by' => $by, 'matched_at' => now()])->save();
        }
    }

    /**
     * The invoices a transfer text names: invoice numbers first, then
     * payment references (prefix + id) in what is left, so a reference
     * prefix that is also the numbers' prefix cannot read a number as an id.
     *
     * @return Collection<int, Invoice>
     */
    public function invoicesNamedIn(string $text): Collection
    {
        $compact = self::compact($text);
        if ($compact === '') {
            return collect();
        }

        $found = collect();
        $numbers = Invoice::query()
            ->whereIn(DB::raw('LOWER(status)'), self::OPEN_STATUSES)
            ->orWhere('updated_at', '>=', now()->subYear())
            ->pluck('invoice_num', 'id');
        // Longest numbers first, so INV-0001234 is not taken for INV-000123.
        foreach ($numbers->sortByDesc(fn ($number) => strlen(self::compact((string) $number))) as $id => $number) {
            $needle = self::compact((string) $number);
            if (strlen($needle) < 4) {
                continue;
            }
            $pattern = '/'.preg_quote($needle, '/').'(?![0-9])/';
            if (preg_match($pattern, $compact) === 1) {
                $found->push((int) $id);
                $compact = (string) preg_replace($pattern, '#', $compact);
            }
        }

        $prefix = self::compact((string) Setting::get('PaymentReferencePrefix', 'INV')) ?: 'INV';
        if (preg_match_all('/'.preg_quote($prefix, '/').'0*([1-9][0-9]{0,9})(?![0-9])/', $compact, $matches) > 0) {
            foreach ($matches[1] as $id) {
                $found->push((int) $id);
            }
        }

        $ids = $found->unique()->values()->all();

        return $ids === [] ? collect() : Invoice::whereIn('id', $ids)->get()->sortBy(fn (Invoice $invoice) => array_search($invoice->id, $ids, true))->values();
    }

    /**
     * Open invoices that owe exactly this amount: the likely ones for a
     * transfer that names none.
     *
     * @return Collection<int, Invoice>
     */
    private function invoicesOwing(float $amount): Collection
    {
        return Invoice::query()
            ->whereIn(DB::raw('LOWER(status)'), self::OPEN_STATUSES)
            ->whereBetween('total', [$amount - 0.01, $amount + 1000000])
            ->orderBy('due_date')
            ->limit(200)
            ->get()
            ->filter(fn (Invoice $invoice) => abs($this->payments->balance($invoice) - $amount) < self::TOLERANCE)
            ->take(5)
            ->values();
    }

    /** @param  list<int>  $suggested */
    private function review(BankTransaction $transaction, string $reason, array $suggested): void
    {
        $transaction->forceFill(['status' => BankTransaction::REVIEW, 'reason' => $reason, 'suggested_invoice_ids' => $suggested])->save();
    }

    /** @param Collection<int, Invoice> $invoices */
    private function pay(BankTransaction $transaction, Collection $invoices, string $by): void
    {
        DB::transaction(function () use ($transaction, $invoices, $by) {
            $left = round((float) $transaction->amount, 2);
            $last = $invoices->count() - 1;

            foreach ($invoices->values() as $index => $invoice) {
                $share = $index === $last ? $left : min($left, max(0, $this->payments->balance($invoice)));
                if ($share <= 0) {
                    continue;
                }
                $left = round($left - $share, 2);

                // One gateway transaction per invoice paid: PaymentService
                // refuses a transaction id it has seen, which keeps a transfer
                // from being recorded twice, and here from paying only the
                // first of the invoices it covers.
                $this->payments->applyPayment($invoice, self::GATEWAY, 'qonto:'.$transaction->external_id.':'.$invoice->id, $share, [
                    'description' => trim(__('messages.bank_reconciliation.payment_description').' '.$transaction->counterparty),
                ]);
            }

            $transaction->forceFill([
                'status' => BankTransaction::MATCHED,
                'invoice_ids' => $invoices->pluck('id')->all(),
                'matched_by' => $by,
                'matched_at' => now(),
            ])->save();

            ActivityLog::log('Bank transfer '.$transaction->external_id.' ('.number_format((float) $transaction->amount, 2).') paid onto invoice(s) '.$invoices->pluck('invoice_num')->implode(', '), $by, $invoices->first()?->client_id);
        });
    }

    /** Upper case, letters and digits only: how a reference is compared whatever the bank or the customer did to its spacing. */
    public static function compact(string $text): string
    {
        return (string) preg_replace('/[^A-Z0-9]/', '', strtoupper($text));
    }
}
