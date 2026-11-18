<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How a group's products are billed, when not as the rest of the shop: the
 * document (a proforma, the VAT invoice issued on payment) and the days to
 * pay it. Both empty means the general settings, as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_groups', function (Blueprint $table) {
            $table->string('invoice_type', 16)->nullable()->after('hidden');
            $table->unsignedSmallInteger('invoice_due_days')->nullable()->after('invoice_type');
        });
    }

    public function down(): void
    {
        Schema::table('product_groups', function (Blueprint $table) {
            $table->dropColumn(['invoice_type', 'invoice_due_days']);
        });
    }
};
