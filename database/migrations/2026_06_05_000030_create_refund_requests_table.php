<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer-initiated refund requests (account section #12). The customer asks
 * for a refund on one of their orders with a reason (and optional partial
 * amount); a platform admin later reviews it. Roadmap P1-2 — this ships the
 * customer-facing request + status tracking; the admin approval queue + the
 * actual gateway refund are the follow-on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refund_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('order_id')->constrained('orders')->cascadeOnDelete();
            $table->text('reason');
            // null = full refund; a value = requested partial amount.
            $table->decimal('amount_requested', 12, 2)->nullable();
            $table->string('status', 24)->default('pending'); // pending / approved / rejected / cancelled
            $table->text('admin_notes')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refund_requests');
    }
};
