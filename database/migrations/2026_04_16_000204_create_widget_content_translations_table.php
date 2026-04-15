<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('widget_content_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('widget_content_id')->constrained('widget_contents')->cascadeOnDelete();
            $table->foreignId('page_id')->constrained('pages')->cascadeOnDelete();
            $table->string('lang', 5);
            $table->json('widget_content')->nullable();
            $table->timestamps();

            $table->unique(['widget_content_id', 'lang']);
            $table->index(['page_id', 'lang']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('widget_content_translations');
    }
};
