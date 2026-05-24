<?php

use Illuminate\Database\Migrations\Migration;

/**
 * Phase Զ.14 follow-up — force-clear the entire Laravel cache.
 *
 * The previous migration's `\Cache::forget('ui_translations_*')` calls
 * didn't actually flush the in-memory cached translations on production.
 * Without SSH access to run `artisan cache:clear` manually, run it via
 * Artisan::call so the new admin.rbac.* keys actually surface to users
 * instead of literal "admin.rbac.export_matrix" text.
 *
 * One-shot, no down().
 */
return new class extends Migration
{
    public function up(): void
    {
        \Artisan::call('cache:clear');
    }

    public function down(): void
    {
        // no-op
    }
};
