<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Support departments in more than one language.
 *
 * A department held one name, written once (the installer seeds them in
 * English), so a customer choosing where to send a ticket read "Technical
 * Support" and "Billing" whatever their language. The department's own name
 * stays the default language's; a translation row holds another language's.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Can run twice without harm (RELEASING.md).
        if (Schema::hasTable('ticket_department_translations')) {
            return;
        }

        Schema::create('ticket_department_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_department_id')->constrained('ticket_departments')->cascadeOnDelete();
            $table->string('language', 10);
            $table->string('name');
            $table->timestamps();
            // Named by hand: the generated name runs past MySQL's 64 characters.
            $table->unique(['ticket_department_id', 'language'], 'ticket_dept_translations_dept_language_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_department_translations');
    }
};
