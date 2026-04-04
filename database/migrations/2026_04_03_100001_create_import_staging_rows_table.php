<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_staging_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_session_id')->constrained('import_sessions')->cascadeOnDelete();
            $table->string('entity_type');
            $table->string('sheet_name')->nullable();
            $table->unsignedInteger('row_number');
            $table->string('external_key')->nullable();
            $table->string('parent_external_key')->nullable();
            $table->json('payload_json');
            $table->string('validation_status')->default('pending');
            $table->json('validation_errors_json')->nullable();
            $table->string('commit_status')->default('pending');
            $table->json('commit_errors_json')->nullable();
            $table->unsignedBigInteger('committed_entity_id')->nullable();
            $table->string('payload_hash')->nullable();
            $table->timestamps();

            $table->index(['import_session_id', 'entity_type'], 'import_staging_rows_session_entity_idx');
            $table->index(['import_session_id', 'validation_status'], 'import_staging_rows_session_validation_idx');
            $table->index(['import_session_id', 'commit_status'], 'import_staging_rows_session_commit_idx');
            $table->index(['import_session_id', 'external_key'], 'import_staging_rows_session_external_key_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_staging_rows');
    }
};
