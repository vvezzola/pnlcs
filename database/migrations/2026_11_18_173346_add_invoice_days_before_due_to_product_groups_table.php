<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How many days before its due date a renewal of the group is invoiced, each
 * on its own day rather than on the consolidated billing day. Empty means the
 * general settings, as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_groups', function (Blueprint $table) {
            $table->unsignedSmallInteger('invoice_days_before_due')->nullable()->after('invoice_due_days');
        });
    }

    public function down(): void
    {
        Schema::table('product_groups', function (Blueprint $table) {
            $table->dropColumn('invoice_days_before_due');
        });
    }
};
