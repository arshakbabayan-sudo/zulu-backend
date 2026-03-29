<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('company_applications', function (Blueprint $table) {
            $table->id();
            $table->string('company_name', 255);
            $table->string('business_email', 255);
            $table->string('legal_address', 500);
            $table->string('actual_address', 500);
            $table->string('country', 100);
            $table->string('city', 100);
            $table->string('phone', 50);
            $table->string('tax_id', 100);
            $table->string('contact_person', 255);
            $table->string('position', 255);
            $table->string('state_certificate_path', 500)->nullable();
            $table->string('license_path', 500)->nullable();
            $table->string('status', 32)->default('pending');
            $table->text('rejection_reason')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->timestamps();

            $table->index('status');
            $table->index('business_email');
            $table->index('submitted_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('company_applications');
    }
};
