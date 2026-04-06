<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ui_translations', function (Blueprint $table): void {
            $table->id();
            $table->string('language_code', 8);
            $table->string('key', 255);
            $table->text('value');
            $table->timestamps();

            $table->unique(['language_code', 'key'], 'ui_translations_lang_key_unique');
            $table->index('language_code', 'ui_translations_language_code_idx');

            $table->foreign('language_code')
                ->references('code')
                ->on('supported_languages')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ui_translations');
    }
};
