<?php

namespace App\Console\Commands;

use App\Mail\AccountInvitationMail;
use App\Models\ActivityLog;
use App\Models\Admin;
use App\Models\Client;
use App\Models\UserInvite;
use App\Services\ClientInviteService;
use App\Support\ClientPermissions;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Mails the first login invitation to every active customer account that has
 * no login yet: the accounts brought in from another system. The address is
 * the account's email, or its billing email when the account email is a
 * placeholder that cannot receive mail. The invitation is staff's (--admin),
 * so the first login accepted becomes the account's owner.
 *
 * The mail is AccountInvitationMail (template "Account Invitation"). Accounts
 * with an invitation still open are left alone unless --resend, so running it
 * again only reaches the ones still missing. Mail is sent at once, not queued,
 * so the result printed is the result. Dry run unless --send.
 */
class SendClientInvitesCommand extends Command
{
    protected $signature = 'pnlcs:send-client-invites
        {--admin= : Username of the staff member the invitations come from}
        {--client=* : Only these client ids}
        {--resend : Also accounts whose invitation is still open (the old link stops working)}
        {--send : Send the mail; without it nothing is created or sent}';

    protected $description = 'Mail the first login invitation to customer accounts that have no login';

    /** Top-level domains that never receive mail (RFC 2606, RFC 6761). */
    private const NO_MAIL_TLDS = ['invalid', 'test', 'example', 'localhost'];

    public function handle(ClientInviteService $invites): int
    {
        $admin = Admin::where('username', (string) $this->option('admin'))->first();
        if (! $admin) {
            $this->error('Give the username of an existing staff member with --admin.');

            return Command::FAILURE;
        }

        $send = (bool) $this->option('send');
        $this->line($send
            ? 'Sending with the "'.config('mail.default').'" mailer.'
            : 'Dry run: nothing is created or sent. Add --send to send.');

        $clients = Client::active()
            ->whereDoesntHave('users')
            ->when($this->option('client'), fn ($query, array $ids) => $query->whereIn('id', $ids))
            ->orderBy('id')
            ->get();

        $done = ['sent' => 0, 'skipped' => 0, 'failed' => 0];

        // Accounts asked for by id that the query left out: say why, rather
        // than finish on "sent 0, skipped 0" with no word about them.
        foreach (array_diff(array_map('intval', $this->option('client')), $clients->pluck('id')->all()) as $id) {
            $asked = Client::withCount('users')->find($id);
            $reason = match (true) {
                $asked === null => 'no such client',
                $asked->users_count > 0 => 'it already has a login',
                default => 'it is not active',
            };
            $this->line("  #{$id}: skipped, {$reason}");
            $done['skipped']++;
        }

        foreach ($clients as $client) {
            $name = "#{$client->id} ".trim($client->company_name ?: $client->first_name.' '.$client->last_name);
            $email = $this->address($client);

            if ($email === null) {
                $this->line("  {$name}: skipped, no address that receives mail");
                $done['skipped']++;

                continue;
            }

            if (! $this->option('resend') && UserInvite::where('client_id', $client->id)->get()->contains(fn (UserInvite $invite) => $invite->isOpen())) {
                $this->line("  {$name}: skipped, an invitation is still open");
                $done['skipped']++;

                continue;
            }

            if (! $send) {
                $this->line("  {$name}: would invite {$email}");
                $done['sent']++;

                continue;
            }

            try {
                [$invite, $link] = $invites->issue($client, $email, ClientPermissions::ALL, $admin->id);
                Mail::to($invite->email)->send(new AccountInvitationMail($client, $link));
                ActivityLog::log("Invitation for {$invite->email} sent", $admin->username, $client->id);
                $this->line("  {$name}: invited {$invite->email}");
                $done['sent']++;
            } catch (Throwable $e) {
                $this->error("  {$name}: not sent to {$email}: {$e->getMessage()}");
                $done['failed']++;
            }
        }

        $this->info(($send ? 'Sent' : 'Would send')." {$done['sent']}, skipped {$done['skipped']}, failed {$done['failed']}.");

        return $done['failed'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function address(Client $client): ?string
    {
        foreach ([$client->email, $client->billing_email] as $candidate) {
            $email = strtolower(trim((string) $candidate));
            $tld = substr(strrchr($email, '.') ?: '', 1);
            if (filter_var($email, FILTER_VALIDATE_EMAIL) && ! in_array($tld, self::NO_MAIL_TLDS, true)) {
                return $email;
            }
        }

        return null;
    }
}
