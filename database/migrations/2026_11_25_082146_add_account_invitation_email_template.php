<?php

use App\Models\EmailTemplate;
use Illuminate\Database\Migrations\Migration;

/**
 * The first login invitation (pnlcs:send-client-invites, AccountInvitationMail)
 * binds to this template through EmailTemplateService::MAP, so an operator can
 * reword it or switch it off. Created through the model: EmailTemplateObserver
 * copies it into every other language, with the shipped translation where
 * there is one. EmailTemplateSeeder covers fresh installs.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (EmailTemplate::where('name', 'Account Invitation')->where('language', 'en')->exists()) {
            return;
        }

        EmailTemplate::create([
            'type' => 'general',
            'name' => 'Account Invitation',
            'language' => 'en',
            'subject' => 'Your client area at {CompanyName} is ready',
            'message' => "Dear {client_name},\n\nYour account at {CompanyName} now has a client area: your services and their renewal dates, your invoices and your payments, all in one place.\n\nTo sign in for the first time, set your password here:\n{invite_url}\n\nThe link works for {invite_days} days and only once. If it has run out, reply to this email and we will send you a new one.\n\nWhen you first sign in, check your billing details: company name, address and tax numbers. They go on every invoice, and an invoice with wrong details can be rejected.\n\nIf you have any questions, just reply to this email.\n\n{CompanyName}",
        ]);
    }

    public function down(): void
    {
        EmailTemplate::where('name', 'Account Invitation')->delete();
    }
};
