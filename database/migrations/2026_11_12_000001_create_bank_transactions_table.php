<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('bank_transactions')) {
            return;
        }

        Schema::create('bank_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('source', 30);
            $table->string('external_id', 100);
            $table->timestamp('booked_at')->nullable()->index();
            $table->decimal('amount', 14, 2);
            $table->string('currency', 3)->default('EUR');
            $table->string('counterparty')->nullable();
            $table->string('counterparty_iban', 64)->nullable();
            $table->text('reference')->nullable();
            $table->string('status', 20)->index();
            $table->string('reason', 30)->nullable();
            $table->json('invoice_ids')->nullable();
            $table->json('suggested_invoice_ids')->nullable();
            $table->string('matched_by')->nullable();
            $table->timestamp('matched_at')->nullable();
            $table->timestamps();

            $table->unique(['source', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_transactions');
    }
};
