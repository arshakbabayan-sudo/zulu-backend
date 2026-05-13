<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('footer_columns', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 64)->unique();
            $table->string('title_en');
            $table->string('title_ru')->nullable();
            $table->string('title_hy')->nullable();
            $table->integer('position')->default(0);
            $table->boolean('is_visible')->default(true);
            $table->timestamps();

            $table->index(['is_visible', 'position'], 'footer_columns_visible_position_idx');
        });

        Schema::create('footer_links', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('column_id');
            $table->string('label_en');
            $table->string('label_ru')->nullable();
            $table->string('label_hy')->nullable();
            $table->string('url')->default('#');
            $table->integer('position')->default(0);
            $table->boolean('is_visible')->default(true);
            $table->boolean('open_in_new_tab')->default(false);
            $table->timestamps();

            $table->index(['column_id', 'position'], 'footer_links_column_position_idx');
            $table->foreign('column_id')
                ->references('id')->on('footer_columns')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('footer_links');
        Schema::dropIfExists('footer_columns');
    }
};
