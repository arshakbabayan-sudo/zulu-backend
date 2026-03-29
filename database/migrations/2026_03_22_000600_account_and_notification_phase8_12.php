<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'phone')) {
                $table->string('phone', 32)->nullable();
            }
            if (! Schema::hasColumn('users', 'preferred_language')) {
                $table->string('preferred_language', 8)->nullable()->default('en');
            }
            if (! Schema::hasColumn('users', 'avatar')) {
                $table->string('avatar', 2048)->nullable();
            }
            if (! Schema::hasColumn('users', 'birth_date')) {
                $table->date('birth_date')->nullable();
            }
            if (! Schema::hasColumn('users', 'nationality')) {
                $table->string('nationality', 64)->nullable();
            }
        });

        if (! Schema::hasTable('saved_items')) {
            Schema::create('saved_items', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('offer_id')->constrained('offers')->cascadeOnDelete();
                $table->string('module_type', 32);
                $table->string('status', 32)->default('active');
                $table->timestamps();

                $table->unique(['user_id', 'offer_id']);
                $table->index('user_id');
            });
        }

        Schema::table('notifications', function (Blueprint $table): void {
            if (! Schema::hasColumn('notifications', 'event_type')) {
                $table->string('event_type', 64)->nullable();
            }
            if (! Schema::hasColumn('notifications', 'subject_type')) {
                $table->string('subject_type', 64)->nullable();
            }
            if (! Schema::hasColumn('notifications', 'subject_id')) {
                $table->unsignedBigInteger('subject_id')->nullable();
            }
            if (! Schema::hasColumn('notifications', 'related_company_id')) {
                $table->unsignedBigInteger('related_company_id')->nullable();
                $table->foreign('related_company_id')
                    ->references('id')
                    ->on('companies')
                    ->nullOnDelete();
            }
            if (! Schema::hasColumn('notifications', 'priority')) {
                $table->string('priority', 32)->nullable()->default('normal');
            }

            $table->index(['user_id', 'status']);
        });

        if (Schema::hasTable('permissions')) {
            $ts = now();
            foreach (['account.update_profile', 'saved_items.manage'] as $name) {
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
                'account.update_profile',
                'saved_items.manage',
            ])->delete();
        }

        Schema::dropIfExists('saved_items');

        Schema::table('notifications', function (Blueprint $table): void {
            $table->dropIndex(['user_id', 'status']);

            if (Schema::hasColumn('notifications', 'related_company_id')) {
                $table->dropForeign(['related_company_id']);
            }

            foreach (['priority', 'related_company_id', 'subject_id', 'subject_type', 'event_type'] as $col) {
                if (Schema::hasColumn('notifications', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        Schema::table('users', function (Blueprint $table): void {
            foreach (['nationality', 'birth_date', 'avatar', 'preferred_language', 'phone'] as $col) {
                if (Schema::hasColumn('users', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
