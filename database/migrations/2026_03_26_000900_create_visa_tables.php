<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visas', function (Blueprint $table) {
            if (! Schema::hasColumn('visas', 'country_id')) {
                $table->unsignedBigInteger('country_id')->nullable()->after('offer_id');
            }
            if (! Schema::hasColumn('visas', 'name')) {
                $table->string('name')->nullable()->after('country');
            }
            if (! Schema::hasColumn('visas', 'price')) {
                $table->decimal('price', 12, 2)->nullable()->after('name');
            }
            if (! Schema::hasColumn('visas', 'description')) {
                $table->text('description')->nullable()->after('price');
            }
            if (! Schema::hasColumn('visas', 'required_documents')) {
                $table->json('required_documents')->nullable()->after('description');
            }
        });

        Schema::create('visa_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('visa_id')->constrained('visas')->cascadeOnDelete();
            $table->string('status')->default('pending');
            $table->string('passport_number');
            $table->date('entry_date')->nullable();
            $table->date('exit_date')->nullable();
            $table->json('files')->nullable();
            $table->text('admin_notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visa_applications');

        Schema::table('visas', function (Blueprint $table) {
            $dropColumns = [];

            foreach (['country_id', 'name', 'price', 'description', 'required_documents'] as $column) {
                if (Schema::hasColumn('visas', $column)) {
                    $dropColumns[] = $column;
                }
            }

            if ($dropColumns !== []) {
                $table->dropColumn($dropColumns);
            }
        });
    }
};
