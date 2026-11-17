<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the customer was last told that a service is running out of disk
 * space (DiskUsageWarningService). Set when the warning goes out, cleared once
 * the usage is back under the threshold, so each crossing is told once.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('services', 'disk_warning_sent_at')) {
            return;
        }

        Schema::table('services', function (Blueprint $table) {
            $table->timestamp('disk_warning_sent_at')->nullable()->after('bw_limit');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('services', 'disk_warning_sent_at')) {
            return;
        }

        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn('disk_warning_sent_at');
        });
    }
};
