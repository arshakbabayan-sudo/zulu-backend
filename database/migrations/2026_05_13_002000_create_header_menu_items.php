<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('header_menu_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('label_en');
            $table->string('label_ru')->nullable();
            $table->string('label_hy')->nullable();
            $table->string('url')->default('#');
            $table->integer('position')->default(0);
            $table->boolean('is_visible')->default(true);
            $table->string('icon')->nullable(); // lucide-react icon name (e.g. "phone") — optional
            $table->boolean('open_in_new_tab')->default(false);
            $table->timestamps();

            $table->index(['parent_id', 'position'], 'header_menu_parent_position_idx');
            $table->index(['is_visible', 'position'], 'header_menu_visible_position_idx');

            $table->foreign('parent_id')
                ->references('id')->on('header_menu_items')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('header_menu_items');
    }
};
