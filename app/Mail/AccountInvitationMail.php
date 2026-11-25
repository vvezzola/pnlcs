<?php

namespace App\Mail;

use App\Mail\Concerns\LocalizesToRecipient;
use App\Models\Client;
use App\Models\Setting;
use App\Models\UserInvite;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The first login invitation to an account brought in from another system
 * (pnlcs:send-client-invites): the link to set a password, what to check on
 * first sign-in, and when the renewal notice and the invoice come.
 *
 * Not queued: the command sends one at a time and prints what happened.
 * Bound to the "Account Invitation" template, so the wording can be changed
 * or the mail switched off from the email templates screen.
 */
class AccountInvitationMail extends Mailable
{
    use LocalizesToRecipient;

    public function __construct(
        public Client $client,
        public string $inviteUrl,
    ) {
        $this->localizeTo($this->client);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('email.account_invitation.subject', ['company' => company_name()]));
    }

    public function content(): Content
    {
        $noticeDay = (int) Setting::get('RenewalNoticeDay', 0);
        $billingDay = (int) Setting::get('ConsolidatedBillingDay', 0);

        return new Content(
            view: 'emails.account-invitation',
            with: [
                'client' => $this->client,
                'inviteUrl' => $this->inviteUrl,
                'validDays' => UserInvite::VALID_DAYS,
                // Said only when both are set: the notice lists what that
                // billing day invoices.
                'noticeDay' => $noticeDay >= 1 && $noticeDay <= 28 && $billingDay >= 1 && $billingDay <= 28 ? $noticeDay : null,
                'billingDay' => $billingDay,
                'companyName' => company_name(),
            ],
        );
    }
}
