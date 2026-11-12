<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sdi_submissions')) {
            return;
        }

        Schema::create('sdi_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->index();
            $table->string('provider', 30);
            $table->string('external_id', 100)->nullable();
            $table->string('file_name')->nullable();
            $table->string('sdi_identifier', 100)->nullable();
            $table->string('state', 40)->index();
            $table->text('message')->nullable();
            $table->string('sent_by')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('state_at')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sdi_submissions');
    }
};
