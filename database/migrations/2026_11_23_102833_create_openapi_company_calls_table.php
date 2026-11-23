<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Every paid call to OpenAPI's Company service, so the cost can be followed. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('openapi_company_calls')) {
            return;
        }

        Schema::create('openapi_company_calls', function (Blueprint $table) {
            $table->id();
            $table->string('endpoint', 40);
            $table->string('vat_code', 20)->index();
            $table->unsignedSmallInteger('status');
            $table->string('purpose', 20);
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('openapi_company_calls');
    }
};
