<?php

use App\Models\Permission;
use App\Models\Role;
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
        Schema::create('visas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offer_id')->unique()->constrained('offers')->cascadeOnDelete();
            $table->string('country');
            $table->string('visa_type');
            $table->unsignedInteger('processing_days')->nullable();
            $table->timestamps();
        });

        $pv = Permission::firstOrCreate(['name' => 'visas.view']);
        $pc = Permission::firstOrCreate(['name' => 'visas.create']);

        foreach (Role::query()->get() as $role) {
            $role->permissions()->syncWithoutDetaching([$pv->id, $pc->id]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('visas');

        Permission::query()->whereIn('name', ['visas.view', 'visas.create'])->delete();
    }
};
