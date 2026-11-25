<?php

use App\Listeners\ApplyEmailTemplate;
use App\Mail\AccountInvitationMail;
use App\Models\Admin;
use App\Models\Client;
use App\Models\EmailTemplate;
use App\Models\Setting;
use App\Models\User;
use App\Models\UserInvite;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;

/*
 * pnlcs:send-client-invites mails the first login invitation to the active
 * accounts that have no login, from a staff member, so the first login
 * accepted owns the account.
 */

beforeEach(function () {
    Mail::fake();
    $this->admin = Admin::factory()->create(['username' => 'desk']);
});

function invitedLink(string $email): string
{
    $mail = Mail::sent(AccountInvitationMail::class, fn (AccountInvitationMail $mail) => $mail->hasTo($email))->first();

    return parse_url($mail->inviteUrl, PHP_URL_PATH);
}

test('a dry run lists the accounts and neither stores nor sends anything', function () {
    Client::factory()->create(['email' => 'rossi@example.com']);

    $this->artisan('pnlcs:send-client-invites', ['--admin' => 'desk'])
        ->expectsOutputToContain('would invite rossi@example.com')
        ->assertSuccessful();

    expect(UserInvite::count())->toBe(0);
    Mail::assertNothingSent();
    Mail::assertNothingQueued();
});

test('it sends now to accounts without a login, and the first login accepted owns the account', function () {
    $client = Client::factory()->create(['email' => 'rossi@example.com']);
    $placeholder = Client::factory()->create(['email' => 'cliente-0007@clienti.invalid', 'billing_email' => 'conti@example.com']);
    Client::factory()->create(['email' => 'cliente-0008@clienti.invalid', 'billing_email' => null]);
    Client::factory()->inactive()->create(['email' => 'chiuso@example.com']);
    $withLogin = Client::factory()->create(['email' => 'bianchi@example.com']);
    $withLogin->users()->attach(User::factory()->create()->id, ['owner' => true, 'permissions' => json_encode([])]);

    $this->artisan('pnlcs:send-client-invites', ['--admin' => 'desk', '--send' => true])
        ->expectsOutputToContain('no address that receives mail')
        ->assertSuccessful();

    Mail::assertSent(AccountInvitationMail::class, 2);
    Mail::assertNothingQueued();
    expect(UserInvite::pluck('email')->sort()->values()->all())->toBe(['conti@example.com', 'rossi@example.com'])
        ->and(UserInvite::where('client_id', $placeholder->id)->value('invited_by'))->toBe($this->admin->id);

    $this->post(invitedLink('rossi@example.com'), ['first_name' => 'Mario', 'last_name' => 'Rossi', 'password' => 'long-enough-1', 'password_confirmation' => 'long-enough-1']);
    expect($client->fresh()->owner()?->email)->toBe('rossi@example.com');
});

test('running it again skips open invitations unless --resend, which replaces the link', function () {
    Client::factory()->create(['email' => 'rossi@example.com']);
    $this->artisan('pnlcs:send-client-invites', ['--admin' => 'desk', '--send' => true]);
    $first = invitedLink('rossi@example.com');

    $this->artisan('pnlcs:send-client-invites', ['--admin' => 'desk', '--send' => true])
        ->expectsOutputToContain('an invitation is still open');
    Mail::assertSent(AccountInvitationMail::class, 1);

    $this->artisan('pnlcs:send-client-invites', ['--admin' => 'desk', '--send' => true, '--resend' => true]);
    Mail::assertSent(AccountInvitationMail::class, 2);
    expect(UserInvite::count())->toBe(1);
    $this->get($first)->assertRedirect(route('client.login'));
});

test('--client limits the run, and an unknown staff member stops it', function () {
    $only = Client::factory()->create(['email' => 'rossi@example.com']);
    Client::factory()->create(['email' => 'bianchi@example.com']);

    $this->artisan('pnlcs:send-client-invites', ['--admin' => 'nobody', '--send' => true])->assertFailed();
    Mail::assertNothingSent();

    $this->artisan('pnlcs:send-client-invites', ['--admin' => 'desk', '--client' => [$only->id], '--send' => true])->assertSuccessful();
    Mail::assertSent(AccountInvitationMail::class, 1);
    Mail::assertSent(AccountInvitationMail::class, fn (AccountInvitationMail $mail) => $mail->hasTo('rossi@example.com'));
});

test('the invitation is in the customer\'s language and says when the notice and the invoice come', function () {
    Setting::set('RenewalNoticeDay', '15');
    Setting::set('ConsolidatedBillingDay', '1');
    $client = Client::factory()->create(['language' => 'it']);

    $html = (new AccountInvitationMail($client, 'https://panel.example/invite/abc'))->render();

    expect($html)->toContain('https://panel.example/invite/abc')
        ->toContain(__('email.account_invitation.button', [], 'it'))
        ->toContain(e(__('email.account_invitation.notice', ['notice_day' => 15, 'billing_day' => 1], 'it')));

    Setting::set('RenewalNoticeDay', '0');
    expect((new AccountInvitationMail($client, 'https://panel.example/invite/abc'))->render())
        ->not->toContain(e(__('email.account_invitation.notice', ['notice_day' => 0, 'billing_day' => 1], 'it')));
});

test('a reworded template carries the link, and the mail is never copied to anyone else', function () {
    Mail::swap(new MailManager(app()));
    EmailTemplate::updateOrCreate(['name' => 'Account Invitation', 'language' => 'en'], [
        'type' => 'general', 'subject' => 'Welcome', 'message' => 'Start here: {invite_url} ({invite_days} days)', 'custom' => true, 'disabled' => false,
    ]);
    Setting::set(ApplyEmailTemplate::COPY_SETTING, 'archive@example.com');
    $client = Client::factory()->create(['language' => 'en']);
    $client->contacts()->create(['first_name' => 'Ada', 'last_name' => 'Colleague', 'email' => 'ada@example.com', 'general_emails' => true]);
    $sent = new ArrayObject;
    Event::listen(MessageSent::class, fn ($event) => $sent->append($event->message));

    Mail::to('rossi@example.com')->send(new AccountInvitationMail($client, 'https://panel.example/invite/abc'));

    $message = $sent[0];
    expect(quoted_printable_decode($message->getTextBody()))->toBe('Start here: https://panel.example/invite/abc ('.UserInvite::VALID_DAYS.' days)')
        ->and($message->getBcc())->toBe([])
        ->and($message->getCc())->toBe([]);
});
