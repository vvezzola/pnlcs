<?php

use App\Mail\BulkMassMail;
use App\Models\Admin;
use App\Models\Client;
use App\Models\User;
use App\Models\UserInvite;
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
    $mail = Mail::sent(BulkMassMail::class, fn (BulkMassMail $mail) => $mail->hasTo($email))->first();
    preg_match('~https?://\S+~', $mail->mailBody, $match);

    return parse_url($match[0], PHP_URL_PATH);
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

    Mail::assertSent(BulkMassMail::class, 2);
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
    Mail::assertSent(BulkMassMail::class, 1);

    $this->artisan('pnlcs:send-client-invites', ['--admin' => 'desk', '--send' => true, '--resend' => true]);
    Mail::assertSent(BulkMassMail::class, 2);
    expect(UserInvite::count())->toBe(1);
    $this->get($first)->assertRedirect(route('client.login'));
});

test('--client limits the run, and an unknown staff member stops it', function () {
    $only = Client::factory()->create(['email' => 'rossi@example.com']);
    Client::factory()->create(['email' => 'bianchi@example.com']);

    $this->artisan('pnlcs:send-client-invites', ['--admin' => 'nobody', '--send' => true])->assertFailed();
    Mail::assertNothingSent();

    $this->artisan('pnlcs:send-client-invites', ['--admin' => 'desk', '--client' => [$only->id], '--send' => true])->assertSuccessful();
    Mail::assertSent(BulkMassMail::class, 1);
    Mail::assertSent(BulkMassMail::class, fn (BulkMassMail $mail) => $mail->hasTo('rossi@example.com'));
});
