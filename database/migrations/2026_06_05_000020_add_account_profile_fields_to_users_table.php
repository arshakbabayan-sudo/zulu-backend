<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extra B2C-account profile fields (account sections #1 Profile + #14
 * Preferences). `name` stays the display/first name; `surname` is added
 * alongside it (non-breaking). travel_preferences is a small JSON bag
 * (seat / meal / room_type / accessibility[]).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('surname')->nullable()->after('name');
            $table->string('gender', 16)->nullable()->after('birth_date');
            $table->string('preferred_currency', 8)->nullable()->after('preferred_language');
            $table->string('timezone', 64)->nullable()->after('preferred_currency');
            $table->string('emergency_contact_name')->nullable();
            $table->string('emergency_contact_phone', 40)->nullable();
            $table->string('emergency_contact_relationship', 40)->nullable();
            $table->json('travel_preferences')->nullable();
            $table->boolean('marketing_opt_in')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'surname',
                'gender',
                'preferred_currency',
                'timezone',
                'emergency_contact_name',
                'emergency_contact_phone',
                'emergency_contact_relationship',
                'travel_preferences',
                'marketing_opt_in',
            ]);
        });
    }
};
