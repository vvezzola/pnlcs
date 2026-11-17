<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A customer's own payment terms: some business customers pay at 60 or 90
 * days, or not at the end of the month. Null in either column means the
 * general setting (InvoiceDueDays, InvoiceDueEndOfMonth) applies, so every
 * existing customer keeps today's terms.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('clients', 'payment_terms_days')) {
            return;
        }

        Schema::table('clients', function (Blueprint $table) {
            $table->unsignedSmallInteger('payment_terms_days')->nullable()->after('override_auto_suspend');
            $table->boolean('payment_terms_end_of_month')->nullable()->after('payment_terms_days');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('clients', 'payment_terms_days')) {
            return;
        }

        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['payment_terms_days', 'payment_terms_end_of_month']);
        });
    }
};
