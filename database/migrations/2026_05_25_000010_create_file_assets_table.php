<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * File manager — Phase Ե (2026-05-25).
 *
 * Stores metadata for files uploaded through the admin /admin-redesign/files
 * page. The bytes themselves live on whatever Laravel disk is configured via
 * the FILESYSTEM_DISK env var (default = `local`, mapped to
 * storage/app/private). Migrating to S3 later = swap the env var; rows here
 * stay valid because `disk` + `path` are stored explicitly.
 *
 * Folder paths are stored as strings (e.g. "/", "/contracts", "/hotels/2026").
 * Folder creation = inserting a zero-byte `.folder` marker file at that path
 * — the controller filters those out of listings.
 *
 * Visibility:
 *   - private  → only uploader + super-admin
 *   - company  → all users with membership in `company_id` (+ super-admin)
 *   - public   → any authenticated user
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('file_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('uploaded_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->string('folder', 255)->default('/');
            $table->string('filename', 255);
            $table->string('mime_type', 100);
            $table->bigInteger('size_bytes');
            $table->string('disk', 32)->default('local');
            $table->string('path', 500);
            $table->string('visibility', 16)->default('private');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'folder']);
            $table->index('uploaded_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('file_assets');
    }
};
