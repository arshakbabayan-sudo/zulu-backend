<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Roadmap §4 — External API / supplier connections.
 *
 * supplier_connections: one row per operator-company ↔ external inventory
 * base link. Credentials are encrypted at rest (model casts). `status`
 * reflects the LAST test/sync outcome: untested | ok | failed.
 *
 * supplier_imported_items: idempotency ledger — which external item became
 * which local offer, so re-imports update instead of duplicating. Rows
 * cascade away with the connection; the imported offers themselves are
 * kept (disconnecting must never delete inventory).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('base_url', 500);
            // Encrypted via model casts — text because ciphertext outgrows the plaintext.
            $table->text('login');
            $table->text('password');
            $table->string('status', 16)->default('untested');
            $table->timestamp('last_tested_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->text('last_error')->nullable();
            $table->unsignedInteger('items_imported')->default(0);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id']);
        });

        Schema::create('supplier_imported_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_connection_id')->constrained('supplier_connections')->cascadeOnDelete();
            $table->string('item_type', 16);
            $table->string('external_id', 191);
            $table->foreignId('offer_id')->constrained('offers')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(
                ['supplier_connection_id', 'item_type', 'external_id'],
                'sii_connection_item_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_imported_items');
        Schema::dropIfExists('supplier_connections');
    }
};
