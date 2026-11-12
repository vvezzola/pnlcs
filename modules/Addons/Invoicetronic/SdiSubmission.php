<?php

namespace Modules\Addons\Invoicetronic;

use App\Models\Invoice;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One attempt to deliver an invoice to the SDI, and what the SDI made of it.
 * An invoice the SDI rejected is fixed and sent again: the new attempt is a
 * new row, so the history of an invoice stays readable.
 *
 * The state is ours while the file has not reached the SDI (sending, error,
 * submitted), then the SDI's own word for it (Inviato, Consegnato, Scartato...).
 *
 * @property int $id
 * @property int $invoice_id
 * @property string $provider
 * @property string|null $external_id
 * @property string|null $file_name
 * @property string|null $sdi_identifier
 * @property string $state
 * @property string|null $message
 * @property string|null $sent_by
 * @property Carbon|null $sent_at
 * @property Carbon|null $state_at
 * @property Carbon|null $checked_at
 */
class SdiSubmission extends Model
{
    /** Recorded before the file is posted; a post that did not get through is retried under the same idempotency key. */
    public const SENDING = 'sending';

    /** The file could not be built, or the intermediary refused it: staff fix the invoice and send it again. */
    public const ERROR = 'error';

    /** Accepted by the intermediary, not yet handed on by the SDI. */
    public const SUBMITTED = 'submitted';

    public const DELIVERED = 'Consegnato';

    public const REJECTED = 'Scartato';

    /** The SDI could not deliver it: the invoice is issued, and the buyer finds it in their tax drawer. */
    public const UNDELIVERABLE = 'ImpossibilitaDiRecapito';

    /** States after which nothing more comes from the SDI. */
    public const FINAL = [
        self::DELIVERED, self::REJECTED, self::UNDELIVERABLE,
        'AccettatoDalDestinatario', 'RifiutatoDalDestinatario', 'DecorrenzaTermini', 'AttestazioneTrasmissioneFattura',
    ];

    /** States that need staff to correct the invoice and send it again. */
    public const NEEDS_ACTION = [self::ERROR, self::REJECTED, 'RifiutatoDalDestinatario'];

    protected $table = 'sdi_submissions';

    protected $fillable = [
        'invoice_id', 'provider', 'external_id', 'file_name', 'sdi_identifier', 'state', 'message',
        'sent_by', 'sent_at', 'state_at', 'checked_at',
    ];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime', 'state_at' => 'datetime', 'checked_at' => 'datetime'];
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function isPending(): bool
    {
        return ! in_array($this->state, [...self::FINAL, self::ERROR], true);
    }
}
