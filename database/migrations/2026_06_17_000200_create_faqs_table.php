<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Multilingual FAQ / help entries. Content is stored per-language (hy/ru/en) on
 * the row — admins edit all three; the public endpoint returns the active
 * language. Grouped by `category` and ordered by `sort_order`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('faqs', function (Blueprint $table) {
            $table->id();
            $table->string('category', 64)->default('general');
            $table->string('question_hy');
            $table->string('question_ru');
            $table->string('question_en');
            $table->text('answer_hy');
            $table->text('answer_ru');
            $table->text('answer_en');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'category', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('faqs');
    }
};
