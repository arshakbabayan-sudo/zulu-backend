<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 2 Step 2.9 — normalized transfers (one per offer, company from offer).
     * Rebuilds legacy minimal `transfers` (from_location, to_location, vehicle_type) when needed.
     */
    public function up(): void
    {
        if (! Schema::hasTable('transfers')) {
            return;
        }

        if (Schema::hasColumn('transfers', 'transfer_name')) {
            return;
        }

        $rows = DB::table('transfers')->orderBy('id')->get();

        Schema::drop('transfers');

        Schema::create('transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offer_id')->constrained('offers')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('transfer_name');
            $table->string('transfer_type', 32);
            $table->string('vehicle_type', 32);
            $table->string('vehicle_class', 64)->nullable();
            $table->unsignedSmallInteger('passenger_capacity');
            $table->unsignedSmallInteger('luggage_capacity')->nullable();
            $table->string('pickup_type', 32);
            $table->string('dropoff_type', 32);
            $table->text('pickup_location');
            $table->text('dropoff_location');
            $table->decimal('pickup_latitude', 10, 8)->nullable();
            $table->decimal('pickup_longitude', 11, 8)->nullable();
            $table->decimal('dropoff_latitude', 10, 8)->nullable();
            $table->decimal('dropoff_longitude', 11, 8)->nullable();
            $table->decimal('distance_km', 10, 2)->nullable();
            $table->unsignedInteger('duration_minutes')->nullable();
            $table->boolean('meet_and_greet')->default(false);
            $table->unsignedSmallInteger('free_wait_time_minutes')->nullable();
            $table->string('cancellation_policy_type', 64)->nullable();
            $table->dateTime('cancellation_deadline_at')->nullable();
            $table->boolean('free_cancellation')->default(false);
            $table->string('availability_status', 32)->default('available');
            $table->boolean('is_package_eligible')->default(false);
            $table->string('status', 32)->default('draft');
            $table->timestamps();

            $table->unique('offer_id', 'transfers_offer_id_unique');
            $table->index('company_id', 'transfers_company_id_index');
        });

        $allowedVehicles = ['sedan', 'van', 'minibus', 'bus'];
        $now = now();

        foreach ($rows as $row) {
            $offer = DB::table('offers')->where('id', $row->offer_id)->first();
            if ($offer === null) {
                continue;
            }

            $name = $offer->title !== '' && $offer->title !== null
                ? $offer->title
                : 'Legacy transfer #'.$row->id;

            $vt = strtolower(trim((string) ($row->vehicle_type ?? '')));
            if (! in_array($vt, $allowedVehicles, true)) {
                $vt = 'sedan';
            }

            DB::table('transfers')->insert([
                'id' => $row->id,
                'offer_id' => $row->offer_id,
                'company_id' => $offer->company_id,
                'transfer_name' => $name,
                'transfer_type' => 'private',
                'vehicle_type' => $vt,
                'vehicle_class' => null,
                'passenger_capacity' => 4,
                'luggage_capacity' => null,
                'pickup_type' => 'address',
                'dropoff_type' => 'address',
                'pickup_location' => (string) $row->from_location,
                'dropoff_location' => (string) $row->to_location,
                'pickup_latitude' => null,
                'pickup_longitude' => null,
                'dropoff_latitude' => null,
                'dropoff_longitude' => null,
                'distance_km' => null,
                'duration_minutes' => null,
                'meet_and_greet' => false,
                'free_wait_time_minutes' => null,
                'cancellation_policy_type' => null,
                'cancellation_deadline_at' => null,
                'free_cancellation' => false,
                'availability_status' => 'available',
                'is_package_eligible' => false,
                'status' => 'draft',
                'created_at' => $row->created_at ?? $now,
                'updated_at' => $row->updated_at ?? $now,
            ]);
        }

        $this->bumpAutoIncrement('transfers');
    }

    protected function bumpAutoIncrement(string $table): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        $max = (int) DB::table($table)->max('id');
        if ($max > 0) {
            DB::statement('ALTER TABLE '.$table.' AUTO_INCREMENT = '.($max + 1));
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('transfers') || ! Schema::hasColumn('transfers', 'transfer_name')) {
            return;
        }

        $rows = DB::table('transfers')->orderBy('id')->get();

        Schema::drop('transfers');

        Schema::create('transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offer_id')->constrained('offers')->cascadeOnDelete();
            $table->string('from_location');
            $table->string('to_location');
            $table->string('vehicle_type');
            $table->timestamps();
            $table->unique('offer_id', 'transfers_offer_id_unique');
        });

        $now = now();

        foreach ($rows as $row) {
            DB::table('transfers')->insert([
                'id' => $row->id,
                'offer_id' => $row->offer_id,
                'from_location' => strlen((string) $row->pickup_location) <= 255
                    ? (string) $row->pickup_location
                    : substr((string) $row->pickup_location, 0, 255),
                'to_location' => strlen((string) $row->dropoff_location) <= 255
                    ? (string) $row->dropoff_location
                    : substr((string) $row->dropoff_location, 0, 255),
                'vehicle_type' => (string) $row->vehicle_type,
                'created_at' => $row->created_at ?? $now,
                'updated_at' => $row->updated_at ?? $now,
            ]);
        }

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            $maxId = (int) DB::table('transfers')->max('id');
            if ($maxId > 0) {
                DB::statement('ALTER TABLE transfers AUTO_INCREMENT = '.($maxId + 1));
            }
        }
    }
};
