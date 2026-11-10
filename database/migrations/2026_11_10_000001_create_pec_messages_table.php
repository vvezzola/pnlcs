<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pec_messages')) {
            return;
        }

        Schema::create('pec_messages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_id')->nullable()->index();
            $table->unsignedBigInteger('admin_id')->nullable();
            $table->string('recipient');
            $table->string('subject');
            $table->text('body');
            $table->json('attachments')->nullable();
            $table->string('message_id')->unique();
            $table->string('status', 20)->default('sent')->index();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->text('error')->nullable();
            $table->json('receipts')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pec_messages');
    }
};
