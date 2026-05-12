<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ALLOWED_ITEM_TYPES = ['hotel', 'package', 'excursion', 'car', 'transfer', 'flight', 'visa', 'offer'];

    public function up(): void
    {
        if (Schema::hasTable('user_favorites')) {
            return;
        }

        Schema::create('user_favorites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('item_type', 32);
            $table->unsignedBigInteger('item_id');
            $table->timestamps();

            $table->unique(['user_id', 'item_type', 'item_id'], 'user_favorites_user_item_unique');
            $table->index(['user_id', 'item_type'], 'user_favorites_user_type_idx');
            $table->index(['item_type', 'item_id'], 'user_favorites_item_lookup_idx');
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            $allowed = "'".implode("','", self::ALLOWED_ITEM_TYPES)."'";
            DB::statement(
                'ALTER TABLE user_favorites DROP CONSTRAINT IF EXISTS user_favorites_item_type_check'
            );
            DB::statement(
                'ALTER TABLE user_favorites ADD CONSTRAINT user_favorites_item_type_check '
                ."CHECK (item_type IN ({$allowed}))"
            );
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE user_favorites DROP CONSTRAINT IF EXISTS user_favorites_item_type_check');
        }

        Schema::dropIfExists('user_favorites');
    }
};
