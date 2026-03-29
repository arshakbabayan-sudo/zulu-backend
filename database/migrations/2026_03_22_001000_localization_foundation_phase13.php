<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('supported_languages')) {
            Schema::create('supported_languages', function (Blueprint $table): void {
                $table->id();
                $table->string('code', 8)->unique();
                $table->string('name', 64);
                $table->string('name_en', 64);
                $table->boolean('is_default')->default(false);
                $table->boolean('is_enabled')->default(true);
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->timestamps();

                $table->index('is_enabled');
                $table->index('is_default');
            });
        }

        if (! Schema::hasTable('content_translations')) {
            Schema::create('content_translations', function (Blueprint $table): void {
                $table->id();
                $table->string('entity_type', 64);
                $table->unsignedBigInteger('entity_id');
                $table->string('language_code', 8);
                $table->string('field_name', 64);
                $table->text('translated_value');
                $table->timestamps();

                $table->unique(['entity_type', 'entity_id', 'language_code', 'field_name'], 'content_translations_entity_lang_field_unique');
                $table->index(['entity_type', 'entity_id'], 'content_translations_entity_idx');
                $table->index('language_code', 'content_translations_language_code_idx');

                $table->foreign('language_code')
                    ->references('code')
                    ->on('supported_languages')
                    ->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('notification_templates')) {
            Schema::create('notification_templates', function (Blueprint $table): void {
                $table->id();
                $table->string('event_type', 64);
                $table->string('language_code', 8);
                $table->string('channel', 32)->default('in_app');
                $table->string('title_template', 512);
                $table->text('body_template');
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->unique(['event_type', 'language_code', 'channel'], 'notification_templates_event_lang_channel_unique');
                $table->index('event_type');
                $table->index('language_code', 'notification_templates_language_code_idx');

                $table->foreign('language_code')
                    ->references('code')
                    ->on('supported_languages')
                    ->cascadeOnDelete();
            });
        }

        if (Schema::hasTable('supported_languages') && DB::table('supported_languages')->count() === 0) {
            $ts = now();
            DB::table('supported_languages')->insert([
                [
                    'code' => 'en',
                    'name' => 'English',
                    'name_en' => 'English',
                    'is_default' => true,
                    'is_enabled' => true,
                    'sort_order' => 0,
                    'created_at' => $ts,
                    'updated_at' => $ts,
                ],
                [
                    'code' => 'ru',
                    'name' => 'Русский',
                    'name_en' => 'Russian',
                    'is_default' => false,
                    'is_enabled' => true,
                    'sort_order' => 1,
                    'created_at' => $ts,
                    'updated_at' => $ts,
                ],
                [
                    'code' => 'hy',
                    'name' => 'Հայերեն',
                    'name_en' => 'Armenian',
                    'is_default' => false,
                    'is_enabled' => true,
                    'sort_order' => 2,
                    'created_at' => $ts,
                    'updated_at' => $ts,
                ],
            ]);
        }

        if (Schema::hasTable('permissions')) {
            $ts = now();
            foreach (['localization.view', 'localization.manage'] as $name) {
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
                'localization.view',
                'localization.manage',
            ])->delete();
        }

        Schema::dropIfExists('notification_templates');
        Schema::dropIfExists('content_translations');
        Schema::dropIfExists('supported_languages');
    }
};
