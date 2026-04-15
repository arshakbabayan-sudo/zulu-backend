<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type', 32);
            $table->foreignId('parent_id')->nullable()->constrained('locations')->cascadeOnDelete();
            $table->string('slug');
            $table->unsignedSmallInteger('depth')->default(0);
            $table->string('path')->nullable();
            $table->timestamps();

            $table->index('parent_id');
            $table->index('type');
            $table->index('slug');
            $table->index('depth');
            $table->index('path');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('locations');
    }
};

