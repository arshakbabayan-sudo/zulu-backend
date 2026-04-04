<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_chunk_checkpoints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_session_id')->constrained('import_sessions')->cascadeOnDelete();
            $table->string('phase');
            $table->unsignedInteger('chunk_index');
            // Non-null default so (session, phase, chunk_index, entity_type) unique is enforced on SQLite/MySQL.
            $table->string('entity_type')->default('');
            $table->string('status');
            $table->timestamp('processed_at')->nullable();
            $table->json('meta_json')->nullable();
            $table->timestamps();

            $table->unique(
                ['import_session_id', 'phase', 'chunk_index', 'entity_type'],
                'import_chunk_checkpoints_session_phase_chunk_entity_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_chunk_checkpoints');
    }
};
