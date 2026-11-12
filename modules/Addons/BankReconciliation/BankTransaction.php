<?php

namespace Modules\Addons\BankReconciliation;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * An incoming transfer read from the bank account, and what became of it:
 * paid onto invoices (matched), waiting for staff (review), or set aside
 * (ignored). One row per bank transaction, so nothing is recorded twice.
 *
 * @property int $id
 * @property string $source
 * @property string $external_id
 * @property Carbon|null $booked_at
 * @property string $amount
 * @property string $currency
 * @property string|null $counterparty
 * @property string|null $counterparty_iban
 * @property string|null $reference
 * @property string $status
 * @property string|null $reason
 * @property list<int>|null $invoice_ids
 * @property list<int>|null $suggested_invoice_ids
 * @property string|null $matched_by
 * @property Carbon|null $matched_at
 */
class BankTransaction extends Model
{
    public const MATCHED = 'matched';

    public const REVIEW = 'review';

    public const IGNORED = 'ignored';

    /** Every open invoice the transfer names, and the amount pays them all: waiting only because automatic payment is off. */
    public const REASON_READY = 'ready';

    /** The transfer names open invoices, but the amount is not what they owe (a partial payment, or more). */
    public const REASON_AMOUNT = 'amount_mismatch';

    /** No open invoice number or payment reference in the transfer. */
    public const REASON_NO_REFERENCE = 'no_reference';

    /** The transfer names invoices that are no longer open (already paid, cancelled). */
    public const REASON_NOT_OPEN = 'not_open';

    protected $table = 'bank_transactions';

    protected $fillable = [
        'source', 'external_id', 'booked_at', 'amount', 'currency', 'counterparty', 'counterparty_iban',
        'reference', 'status', 'reason', 'invoice_ids', 'suggested_invoice_ids', 'matched_by', 'matched_at',
    ];

    protected function casts(): array
    {
        return [
            'booked_at' => 'datetime',
            'matched_at' => 'datetime',
            'amount' => 'decimal:2',
            'invoice_ids' => 'array',
            'suggested_invoice_ids' => 'array',
        ];
    }
}
