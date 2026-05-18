<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7.4 — operator-defined custom field schema.
 *
 * Lets operators add custom fields (text / number / boolean / select /
 * multi_select) onto their offers without touching code. Just the schema
 * registry here — actual rendering on operator forms / search filters
 * is per-vertical wiring that lands later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_field_definitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            // Service type the field applies to ('hotel' | 'flight' | ... | 'all').
            $table->string('scope', 32)->default('all');
            $table->string('key', 64);
            $table->string('label', 255);
            // text | number | boolean | select | multi_select | date
            $table->string('field_type', 32);
            // JSON list of options for select / multi_select.
            $table->json('options')->nullable();
            $table->string('help_text', 500)->nullable();
            $table->boolean('is_required')->default(false);
            $table->boolean('show_in_filter')->default(false);
            $table->integer('display_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'scope', 'key']);
            $table->index(['company_id', 'scope', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_field_definitions');
    }
};
