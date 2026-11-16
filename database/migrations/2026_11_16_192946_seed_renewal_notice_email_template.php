<?php

use App\Models\EmailTemplate;
use Illuminate\Database\Migrations\Migration;

/**
 * The renewal notice (pnlcs:renewal-notice) binds to this template through
 * EmailTemplateService::MAP, so an operator can reword it or switch it off.
 * Created through the model: EmailTemplateObserver copies it into every other
 * language, with the shipped translation where there is one.
 * EmailTemplateSeeder covers fresh installs.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (EmailTemplate::where('name', 'Renewal Notice')->where('language', 'en')->exists()) {
            return;
        }

        EmailTemplate::create([
            'type' => 'invoice',
            'name' => 'Renewal Notice',
            'language' => 'en',
            'subject' => 'Renewals we will invoice on {billing_date} - {CompanyName}',
            'message' => "Dear {client_name},\n\nThese services and domains are coming up for renewal. We will invoice them on {billing_date}.\n\n{renewal_list}\n\nIf you no longer want one of them, tell us by {cancel_by}: in your client area at {whmcs_url} use \"Request Cancellation\" on a service, or turn automatic renewal off on a domain. To keep everything, there is nothing to do.\n\n{CompanyName}",
        ]);
    }

    public function down(): void
    {
        EmailTemplate::where('name', 'Renewal Notice')->delete();
    }
};
