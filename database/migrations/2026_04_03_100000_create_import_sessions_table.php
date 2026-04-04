<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('template_version');
            $table->string('file_disk');
            $table->string('file_path');
            $table->string('original_filename');
            $table->string('mime_type')->nullable();
            $table->string('file_checksum', 64)->nullable();
            $table->json('options_json')->nullable();
            $table->boolean('dry_run')->default(false);
            $table->string('sync_mode')->default('partial');
            $table->string('status');
            $table->unsignedInteger('rows_total')->default(0);
            $table->unsignedInteger('rows_valid')->default(0);
            $table->unsignedInteger('rows_invalid')->default(0);
            $table->unsignedInteger('entities_created')->default(0);
            $table->unsignedInteger('entities_updated')->default(0);
            $table->unsignedInteger('entities_skipped')->default(0);
            $table->unsignedInteger('entities_failed')->default(0);
            $table->unsignedInteger('validation_errors_count')->default(0);
            $table->unsignedInteger('commit_errors_count')->default(0);
            $table->string('error_report_path')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_sessions');
    }
};
