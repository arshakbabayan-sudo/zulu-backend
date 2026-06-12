<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Roadmap §4 — custom field VALUE storage.
 *
 * custom_field_definitions has existed since Phase 7.4, but values entered
 * on inventory entities had nowhere to live. One row per (definition,
 * entity); `entity_type` is the definition scope vertical ('hotel' |
 * 'flight' | 'car' | 'transfer' | 'excursion' | 'visa' | 'package') and
 * `entity_id` the integer PK of that vertical's table. No DB-level FK to
 * the entity tables (they are 7 different tables); model-delete purges
 * rows via the HasCustomFieldValues trait, and definition deletion
 * cascades here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_field_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('custom_field_definition_id')
                ->constrained('custom_field_definitions')
                ->cascadeOnDelete();
            $table->string('entity_type', 32);
            $table->unsignedBigInteger('entity_id');
            // JSON so one column carries text / number / boolean / date string / option list.
            $table->json('value')->nullable();
            $table->timestamps();

            $table->unique(
                ['custom_field_definition_id', 'entity_type', 'entity_id'],
                'cfv_definition_entity_unique'
            );
            $table->index(['entity_type', 'entity_id'], 'cfv_entity_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_field_values');
    }
};
