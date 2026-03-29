<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_connections', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('source_type');
            $table->unsignedBigInteger('source_id');
            $table->string('target_type');
            $table->unsignedBigInteger('target_id');
            $table->string('connection_type');
            $table->string('status')->default('pending');
            $table->string('client_targeting')->default('all');
            $table->unsignedBigInteger('company_id');
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_connections');
    }
};
