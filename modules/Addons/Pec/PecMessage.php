<?php

namespace Modules\Addons\Pec;

use App\Models\Client;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One certified message sent, and what the provider's receipts said of it:
 * sent, accepted by the sender's provider, delivered to the recipient's box,
 * or failed.
 *
 * @property list<array{uid: int, type: string, path: string, at: string}>|null $receipts
 */
class PecMessage extends Model
{
    public const SENT = 'sent';

    public const ACCEPTED = 'accepted';

    public const DELIVERED = 'delivered';

    public const FAILED = 'failed';

    protected $table = 'pec_messages';

    protected $fillable = ['client_id', 'admin_id', 'recipient', 'subject', 'body', 'attachments', 'message_id', 'status', 'accepted_at', 'delivered_at', 'error', 'receipts'];

    protected function casts(): array
    {
        return ['attachments' => 'array', 'receipts' => 'array', 'accepted_at' => 'datetime', 'delivered_at' => 'datetime'];
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
