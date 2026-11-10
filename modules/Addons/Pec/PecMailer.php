<?php

namespace Modules\Addons\Pec;

use App\Models\Client;
use App\Models\CustomField;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Sends a certified message from the configured PEC mailbox, one recipient
 * per message so each recipient gets a delivery receipt of their own.
 */
class PecMailer
{
    /** @param  array<string, string>|null  $settings */
    public function __construct(
        private ?TransportInterface $transport = null,
        private ?array $settings = null,
    ) {}

    /**
     * @param  list<UploadedFile>  $attachments
     *
     * @throws RuntimeException when the mailbox is not set up or the provider refuses the message
     */
    public function send(string $recipient, string $subject, string $body, array $attachments = [], ?int $adminId = null, ?int $clientId = null): PecMessage
    {
        $settings = $this->settings();
        $recipient = strtolower(trim($recipient));

        if (filter_var($settings['address'], FILTER_VALIDATE_EMAIL) === false || $settings['password'] === '') {
            throw new RuntimeException(__('messages.pec.not_configured'));
        }
        if (filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException(__('messages.pec.invalid_recipient', ['address' => $recipient]));
        }

        $domain = Str::after($settings['address'], '@');
        $messageId = Str::uuid()->toString().'@'.$domain;

        $email = (new Email)
            ->from(new Address($settings['address']))
            ->to($recipient)
            ->subject($subject)
            ->text($body);
        $email->getHeaders()->addIdHeader('Message-ID', $messageId);
        foreach ($attachments as $file) {
            $email->attachFromPath($file->getRealPath(), $file->getClientOriginalName(), $file->getMimeType() ?: null);
        }

        $this->transport()->send($email);

        return PecMessage::create([
            'client_id' => $clientId ?? $this->clientFor($recipient)?->id,
            'admin_id' => $adminId,
            'recipient' => $recipient,
            'subject' => $subject,
            'body' => $body,
            'attachments' => array_map(fn (UploadedFile $file) => $file->getClientOriginalName(), $attachments),
            'message_id' => '<'.$messageId.'>',
            'status' => PecMessage::SENT,
        ]);
    }

    /** The client whose PEC field, or else whose email, is this address. */
    public function clientFor(string $address): ?Client
    {
        $field = $this->settings()['client_field'];
        if ($field !== '') {
            $custom = CustomField::where('type', 'client')->where('field_name', $field)->first();
            $value = $custom?->values()->whereRaw('LOWER(TRIM(value)) = ?', [$address])->first();
            if ($value) {
                return Client::find($value->rel_id);
            }
        }

        return Client::whereRaw('LOWER(email) = ?', [$address])->first();
    }

    private function transport(): TransportInterface
    {
        if ($this->transport) {
            return $this->transport;
        }

        $settings = $this->settings();
        $port = (int) $settings['smtp_port'];
        $transport = new EsmtpTransport($settings['smtp_host'], $port, $port === 465);
        $transport->setUsername($settings['address']);
        $transport->setPassword($settings['password']);

        return $this->transport = $transport;
    }

    /** @return array<string, string> */
    private function settings(): array
    {
        return $this->settings ??= PecSettings::resolve();
    }
}
