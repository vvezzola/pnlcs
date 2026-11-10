<?php

use App\Models\Admin;
use App\Models\AdminRole;
use App\Models\Client;
use App\Models\CustomField;
use App\Models\CustomFieldValue;
use Illuminate\Support\Facades\Storage;
use Modules\Addons\Pec\PecMailer;
use Modules\Addons\Pec\PecMessage;
use Modules\Addons\Pec\PecReceipts;
use Modules\Addons\Pec\PecServiceProvider;
use Modules\Addons\Pec\ReceiptMailbox;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

/*
 * Certified email: every recipient gets a message of their own, so the
 * provider's delivery receipt proves delivery to that one recipient; the
 * receipts, read back from the sending mailbox, move each message along and
 * are kept as the legal proof.
 */

function pecSettings(array $overrides = []): array
{
    return array_merge([
        'address' => 'fatture@pec.esalogica.test', 'password' => 'secret', 'smtp_host' => 'smtps.pec.example', 'smtp_port' => '465',
        'imap_host' => 'imaps.pec.example', 'imap_port' => '993', 'client_field' => 'Codice destinatario SDI / PEC',
    ], $overrides);
}

function pecTransport(): TransportInterface
{
    return new class implements TransportInterface
    {
        /** @var list<Email> */
        public array $sent = [];

        public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
        {
            $this->sent[] = $message;

            return new SentMessage($message, $envelope ?? Envelope::create($message));
        }

        public function __toString(): string
        {
            return 'fake://';
        }
    };
}

/** @param  array<string, list<string>>  $receipts  message id => raw receipts */
function pecMailbox(array $receipts): ReceiptMailbox
{
    return new class($receipts) implements ReceiptMailbox
    {
        /** @var array<int, string> */
        private array $byUid = [];

        /** @var array<string, list<int>> */
        private array $index = [];

        public function __construct(array $receipts)
        {
            $uid = 100;
            foreach ($receipts as $id => $messages) {
                foreach ($messages as $raw) {
                    $this->byUid[++$uid] = $raw;
                    $this->index[$id][] = $uid;
                }
            }
        }

        public function open(): void {}

        public function search(string $header, string $value): array
        {
            return $this->index[$value] ?? [];
        }

        public function fetch(int $uid): string
        {
            return $this->byUid[$uid];
        }

        public function close(): void {}
    };
}

function pecReceipt(string $type, string $messageId, string $subject = 'ACCETTAZIONE: Avviso'): string
{
    return "From: posta-certificata@pec.aruba.it\r\nSubject: {$subject}\r\nX-Ricevuta: {$type}\r\nX-Riferimento-Message-ID: {$messageId}\r\n\r\nRicevuta.";
}

it('sends from the PEC mailbox and links the message to the client by their PEC field', function () {
    $field = CustomField::create(['type' => 'client', 'field_name' => 'Codice destinatario SDI / PEC', 'field_type' => 'text']);
    $client = Client::factory()->create();
    CustomFieldValue::create(['field_id' => $field->id, 'rel_id' => $client->id, 'value' => ' Mario@PEC.example.it ']);
    $transport = pecTransport();

    $message = (new PecMailer($transport, pecSettings()))->send('mario@pec.example.it', 'Avviso', 'Testo');

    $email = $transport->sent[0];
    expect($email->getFrom()[0]->getAddress())->toBe('fatture@pec.esalogica.test')
        ->and($email->getTo()[0]->getAddress())->toBe('mario@pec.example.it')
        ->and($email->getHeaders()->get('Message-ID')->getBodyAsString())->toBe($message->message_id)
        ->and($message->client_id)->toBe($client->id)
        ->and($message->status)->toBe(PecMessage::SENT);
});

it('refuses to send until the mailbox is set up, or to a malformed address', function (array $settings, string $recipient, string $reason) {
    expect(fn () => (new PecMailer(pecTransport(), pecSettings($settings)))->send($recipient, 'Avviso', 'Testo'))
        ->toThrow(RuntimeException::class, $reason);
    expect(PecMessage::count())->toBe(0);
})->with([
    'no password' => [['password' => ''], 'mario@pec.example.it', 'PEC address and password'],
    'bad recipient' => [[], 'mario-at-pec', 'not a valid address'],
]);

it('moves a message along as its receipts arrive and keeps them', function () {
    Storage::fake('local');
    $message = (new PecMailer(pecTransport(), pecSettings()))->send('mario@pec.example.it', 'Avviso', 'Testo');

    (new PecReceipts(pecMailbox([$message->message_id => [pecReceipt('accettazione', $message->message_id)]])))->refresh();
    expect($message->fresh()->status)->toBe(PecMessage::ACCEPTED);

    $changed = (new PecReceipts(pecMailbox([$message->message_id => [
        pecReceipt('accettazione', $message->message_id),
        pecReceipt('avvenuta-consegna', $message->message_id, 'CONSEGNA: Avviso'),
    ]])))->refresh();

    $message->refresh();
    expect($changed)->toBe(1)
        ->and($message->status)->toBe(PecMessage::DELIVERED)
        ->and($message->delivered_at)->not->toBeNull()
        ->and(array_column($message->receipts, 'type'))->toBe(['accettazione', 'avvenuta-consegna']);
    Storage::disk('local')->assertExists($message->receipts[1]['path']);
});

it('marks a message the provider could not deliver as failed, with the reason', function () {
    Storage::fake('local');
    $message = (new PecMailer(pecTransport(), pecSettings()))->send('nobody@pec.example.it', 'Avviso', 'Testo');

    (new PecReceipts(pecMailbox([$message->message_id => [
        pecReceipt('errore-consegna', $message->message_id, 'AVVISO DI MANCATA CONSEGNA: Avviso'),
    ]])))->refresh();

    expect($message->fresh()->status)->toBe(PecMessage::FAILED)
        ->and($message->fresh()->error)->toBe('AVVISO DI MANCATA CONSEGNA: Avviso');
});

it('sends one message per recipient from the addon page and reports the ones it could not', function () {
    (new PecServiceProvider(app()))->boot();
    app('router')->getRoutes()->refreshNameLookups();
    $transport = pecTransport();
    app()->instance(PecMailer::class, new PecMailer($transport, pecSettings()));

    $this->actingAs(Admin::factory()->create(['role_id' => AdminRole::factory()->fullAdmin()->create()->id]), 'admin')
        ->post(route('admin.pec.send'), [
            'recipients' => "uno@pec.example.it\ndue@pec.example.it, uno@pec.example.it\nnon-valido",
            'subject' => 'Casella in scadenza',
            'body' => 'La casella non verrà rinnovata.',
        ])
        ->assertSessionHas('success')
        ->assertSessionHas('error', fn ($error) => str_contains($error, 'non-valido'));

    expect(count($transport->sent))->toBe(2)
        ->and(PecMessage::pluck('recipient')->sort()->values()->all())->toBe(['due@pec.example.it', 'uno@pec.example.it']);
});
