<?php

use App\Models\EmailTemplate;
use Illuminate\Database\Migrations\Migration;

/**
 * The disk usage warning (DiskUsageWarningService) binds to this template
 * through EmailTemplateService::MAP, so an operator can reword it or switch it
 * off. Created through the model: EmailTemplateObserver copies it into every
 * other language, with the shipped translation where there is one.
 * EmailTemplateSeeder covers fresh installs.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (EmailTemplate::where('name', 'Disk Usage Warning')->where('language', 'en')->exists()) {
            return;
        }

        EmailTemplate::create([
            'type' => 'product',
            'name' => 'Disk Usage Warning',
            'language' => 'en',
            'subject' => 'Your service {service_domain} has used {usage_percent}% of its space - {CompanyName}',
            'message' => "Dear {client_name},\n\nyour service {product_name} ({service_domain}) is using {disk_used} of the {disk_limit} it includes ({usage_percent}%). Mail stored on the server counts too.\n\nWhen the space runs out, the service accepts no new data, incoming mail included. To keep it working, free some space (for example by archiving old mail on your computer) or move to a larger plan: {upgrade_url}\n\n{CompanyName}",
        ]);
    }

    public function down(): void
    {
        EmailTemplate::where('name', 'Disk Usage Warning')->delete();
    }
};
