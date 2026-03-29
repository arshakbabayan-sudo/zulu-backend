<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('service_holds')) {
            Schema::create('service_holds', function (Blueprint $table) {
                $table->id();
                $table->morphs('holdable'); // holdable_type, holdable_id (Flight, Hotel, etc.)
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('booking_id')->nullable()->constrained('bookings')->nullOnDelete();
                $table->integer('quantity')->default(1);
                $table->timestamp('expires_at');
                $table->boolean('released')->default(false);
                $table->timestamps();

                $table->index(['holdable_type', 'holdable_id', 'released', 'expires_at']);
                $table->index('user_id');
                $table->index('expires_at');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('service_holds');
    }
};

