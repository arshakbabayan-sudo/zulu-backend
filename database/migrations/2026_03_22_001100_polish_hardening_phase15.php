<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('reviews')) {
            Schema::create('reviews', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->unsignedBigInteger('package_order_id')->nullable();
                $table->unsignedBigInteger('booking_id')->nullable();
                $table->string('target_entity_type', 64);
                $table->unsignedBigInteger('target_entity_id');
                $table->unsignedTinyInteger('rating');
                $table->text('review_text')->nullable();
                $table->string('status', 32)->default('pending');
                $table->text('moderation_notes')->nullable();
                $table->timestamps();

                $table->unique(['user_id', 'target_entity_type', 'target_entity_id']);
                $table->index(['target_entity_type', 'target_entity_id']);
                $table->index('status');

                $table->foreign('package_order_id')
                    ->references('id')
                    ->on('package_orders')
                    ->nullOnDelete();
                $table->foreign('booking_id')
                    ->references('id')
                    ->on('bookings')
                    ->nullOnDelete();
            });
        }

        if (Schema::hasTable('offers')) {
            Schema::table('offers', function (Blueprint $table): void {
                if (! Schema::hasColumn('offers', 'created_at')) {
                    $table->timestamp('created_at')->nullable();
                }
                if (! Schema::hasColumn('offers', 'updated_at')) {
                    $table->timestamp('updated_at')->nullable();
                }
            });
        }

        if (Schema::hasTable('permissions')) {
            $ts = now();
            foreach (['reviews.create', 'reviews.view', 'reviews.moderate'] as $name) {
                DB::table('permissions')->insertOrIgnore([
                    'name' => $name,
                    'created_at' => $ts,
                    'updated_at' => $ts,
                ]);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('permissions')) {
            DB::table('permissions')->whereIn('name', [
                'reviews.create',
                'reviews.view',
                'reviews.moderate',
            ])->delete();
        }

        if (Schema::hasTable('offers')) {
            $drop = array_values(array_filter([
                Schema::hasColumn('offers', 'created_at') ? 'created_at' : null,
                Schema::hasColumn('offers', 'updated_at') ? 'updated_at' : null,
            ]));
            if ($drop !== []) {
                Schema::table('offers', function (Blueprint $table) use ($drop): void {
                    $table->dropColumn($drop);
                });
            }
        }

        Schema::dropIfExists('reviews');
    }
};
