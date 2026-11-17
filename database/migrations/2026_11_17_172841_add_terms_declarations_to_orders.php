<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The declarations a customer made at checkout besides accepting the terms:
 * the specific approval of the listed clauses (arts. 1341-1342 Italian Civil
 * Code) and the request to start before the withdrawal period ends. Kept on
 * the order next to the terms acceptance, as evidence of what was ticked.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('orders', 'terms_declarations')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->json('terms_declarations')->nullable()->after('terms_documents');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('orders', 'terms_declarations')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropColumn('terms_declarations');
            });
        }
    }
};
