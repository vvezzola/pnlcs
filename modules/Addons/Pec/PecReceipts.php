<?php

namespace Modules\Addons\Pec;

use Illuminate\Support\Facades\Storage;

/**
 * Reads the provider's receipts from the sending mailbox and moves each sent
 * message along: accepted, delivered, or failed. A PEC receipt names the
 * message it answers in X-Riferimento-Message-ID and says what it is in
 * X-Ricevuta; the receipt itself is kept, being the legal proof.
 */
class PecReceipts
{
    /** Receipts are searched for this long; later ones would not change anything. */
    private const WINDOW_DAYS = 30;

    public function __construct(private ?ReceiptMailbox $mailbox = null) {}

    /** @return int messages whose status changed */
    public function refresh(): int
    {
        $pending = PecMessage::whereIn('status', [PecMessage::SENT, PecMessage::ACCEPTED])
            ->where('created_at', '>=', now()->subDays(self::WINDOW_DAYS))
            ->orderBy('id')
            ->get();

        if ($pending->isEmpty()) {
            return 0;
        }

        $mailbox = $this->mailbox();
        $mailbox->open();
        $changed = 0;

        try {
            foreach ($pending as $message) {
                $changed += $this->check($mailbox, $message) ? 1 : 0;
            }
        } finally {
            $mailbox->close();
        }

        return $changed;
    }

    private function check(ReceiptMailbox $mailbox, PecMessage $message): bool
    {
        $known = array_column($message->receipts ?? [], 'uid');
        $receipts = $message->receipts ?? [];
        $before = $message->status;

        foreach ($mailbox->search('X-Riferimento-Message-ID', $message->message_id) as $uid) {
            if (in_array($uid, $known, true)) {
                continue;
            }

            $raw = $mailbox->fetch($uid);
            $type = strtolower($this->header($raw, 'X-Ricevuta') ?? 'sconosciuta');
            $path = 'pec/'.$message->id.'/'.$uid.'-'.$type.'.eml';
            Storage::disk('local')->put($path, $raw);
            $receipts[] = ['uid' => $uid, 'type' => $type, 'path' => $path, 'at' => now()->toIso8601String()];

            match ($type) {
                'accettazione' => $message->accepted_at ??= now(),
                'avvenuta-consegna' => $message->delivered_at ??= now(),
                'non-accettazione', 'errore-consegna', 'rilevazione-virus' => $message->error = $this->header($raw, 'Subject') ?? $type,
                default => null,
            };
        }

        $message->receipts = $receipts;
        $message->status = match (true) {
            $message->error !== null => PecMessage::FAILED,
            $message->delivered_at !== null => PecMessage::DELIVERED,
            $message->accepted_at !== null => PecMessage::ACCEPTED,
            default => PecMessage::SENT,
        };
        $message->save();

        return $message->status !== $before;
    }

    /** One header of a raw message, unfolded and decoded. */
    private function header(string $raw, string $name): ?string
    {
        $head = preg_split("/\r?\n\r?\n/", $raw, 2)[0] ?? '';
        $head = (string) preg_replace("/\r?\n[ \t]+/", ' ', $head);
        if (! preg_match('/^'.preg_quote($name, '/').':\s*(.*)$/mi', $head, $m)) {
            return null;
        }

        return trim(function_exists('iconv_mime_decode') ? (string) iconv_mime_decode($m[1], ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8') : $m[1]);
    }

    private function mailbox(): ReceiptMailbox
    {
        if ($this->mailbox) {
            return $this->mailbox;
        }

        $settings = PecSettings::resolve();

        return new ImapClient($settings['imap_host'], (int) $settings['imap_port'], $settings['address'], $settings['password']);
    }
}
