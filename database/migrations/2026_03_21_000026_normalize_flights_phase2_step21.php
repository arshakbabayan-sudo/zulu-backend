<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Normalize `flights` to Phase 2 Step 2.1 shape (offer + company linkage, deep design fields).
     * Rebuilds the table for SQLite/MySQL compatibility (no column ->change()).
     */
    public function up(): void
    {
        if (! Schema::hasTable('flights')) {
            return;
        }

        if (Schema::hasColumn('flights', 'departure_at')) {
            return;
        }

        $rows = DB::table('flights')->orderBy('id')->get();

        Schema::dropIfExists('flights_new');
        Schema::drop('flights');

        Schema::create('flights_new', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offer_id')->constrained('offers')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('flight_code_internal');
            $table->string('service_type', 32);
            $table->string('departure_country');
            $table->string('departure_city');
            $table->string('departure_airport');
            $table->string('arrival_country');
            $table->string('arrival_city');
            $table->string('arrival_airport');
            $table->string('departure_airport_code', 8)->nullable();
            $table->string('arrival_airport_code', 8)->nullable();
            $table->string('departure_terminal', 32)->nullable();
            $table->string('arrival_terminal', 32)->nullable();
            $table->dateTime('departure_at');
            $table->dateTime('arrival_at');
            $table->unsignedInteger('duration_minutes');
            $table->string('timezone_context', 64)->nullable();
            $table->dateTime('check_in_close_at')->nullable();
            $table->dateTime('boarding_close_at')->nullable();
            $table->string('connection_type', 32);
            $table->unsignedSmallInteger('stops_count');
            $table->text('connection_notes')->nullable();
            $table->text('layover_summary')->nullable();
            $table->string('cabin_class', 32);
            $table->unsignedInteger('seat_capacity_total');
            $table->unsignedInteger('seat_capacity_available');
            $table->string('fare_family')->nullable();
            $table->boolean('seat_map_available')->default(false);
            $table->string('seat_selection_policy')->nullable();
            $table->unsignedTinyInteger('adult_age_from');
            $table->unsignedTinyInteger('child_age_from');
            $table->unsignedTinyInteger('child_age_to');
            $table->unsignedTinyInteger('infant_age_from');
            $table->unsignedTinyInteger('infant_age_to');
            $table->decimal('adult_price', 12, 2);
            $table->decimal('child_price', 12, 2);
            $table->decimal('infant_price', 12, 2);
            $table->boolean('hand_baggage_included');
            $table->boolean('checked_baggage_included');
            $table->string('hand_baggage_weight', 32)->nullable();
            $table->string('checked_baggage_weight', 32)->nullable();
            $table->boolean('extra_baggage_allowed')->default(false);
            $table->text('baggage_notes')->nullable();
            $table->boolean('reservation_allowed');
            $table->boolean('online_checkin_allowed');
            $table->boolean('airport_checkin_allowed');
            $table->string('cancellation_policy_type', 32);
            $table->string('change_policy_type', 32);
            $table->dateTime('reservation_deadline_at')->nullable();
            $table->dateTime('cancellation_deadline_at')->nullable();
            $table->dateTime('change_deadline_at')->nullable();
            $table->text('policy_notes')->nullable();
            $table->boolean('is_package_eligible')->default(true);
            $table->string('status', 32);
            $table->timestamps();

            $table->unique('offer_id', 'flights_offer_id_unique');
            $table->index('company_id', 'flights_company_id_index');
        });

        foreach ($rows as $row) {
            $offer = DB::table('offers')->where('id', $row->offer_id)->first();
            if ($offer === null) {
                continue;
            }

            $departure = \Illuminate\Support\Carbon::parse($row->departure_time);
            $arrival = \Illuminate\Support\Carbon::parse($row->arrival_time);
            $durationMinutes = max(0, abs((int) $departure->diffInMinutes($arrival)));

            DB::table('flights_new')->insert([
                'id' => $row->id,
                'offer_id' => $row->offer_id,
                'company_id' => $offer->company_id,
                'flight_code_internal' => 'LEGACY-'.$row->id,
                'service_type' => 'scheduled',
                'departure_country' => '',
                'departure_city' => $row->from_location ?? '',
                'departure_airport' => '',
                'arrival_country' => '',
                'arrival_city' => $row->to_location ?? '',
                'arrival_airport' => '',
                'departure_airport_code' => null,
                'arrival_airport_code' => null,
                'departure_terminal' => null,
                'arrival_terminal' => null,
                'departure_at' => $departure,
                'arrival_at' => $arrival,
                'duration_minutes' => $durationMinutes,
                'timezone_context' => null,
                'check_in_close_at' => null,
                'boarding_close_at' => null,
                'connection_type' => 'direct',
                'stops_count' => 0,
                'connection_notes' => null,
                'layover_summary' => null,
                'cabin_class' => 'economy',
                'seat_capacity_total' => 0,
                'seat_capacity_available' => 0,
                'fare_family' => null,
                'seat_map_available' => false,
                'seat_selection_policy' => null,
                'adult_age_from' => 18,
                'child_age_from' => 2,
                'child_age_to' => 11,
                'infant_age_from' => 0,
                'infant_age_to' => 1,
                'adult_price' => $offer->price ?? 0,
                'child_price' => $offer->price ?? 0,
                'infant_price' => 0,
                'hand_baggage_included' => false,
                'checked_baggage_included' => false,
                'hand_baggage_weight' => null,
                'checked_baggage_weight' => null,
                'extra_baggage_allowed' => false,
                'baggage_notes' => null,
                'reservation_allowed' => true,
                'online_checkin_allowed' => true,
                'airport_checkin_allowed' => true,
                'cancellation_policy_type' => 'non_refundable',
                'change_policy_type' => 'not_allowed',
                'reservation_deadline_at' => null,
                'cancellation_deadline_at' => null,
                'change_deadline_at' => null,
                'policy_notes' => null,
                'is_package_eligible' => true,
                'status' => 'draft',
                'created_at' => $row->created_at ?? now(),
                'updated_at' => $row->updated_at ?? now(),
            ]);
        }

        Schema::rename('flights_new', 'flights');

        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE flights AUTO_INCREMENT = '.((int) DB::table('flights')->max('id') + 1));
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('flights')) {
            return;
        }

        if (Schema::hasColumn('flights', 'from_location')) {
            return;
        }

        $rows = DB::table('flights')->orderBy('id')->get();

        Schema::dropIfExists('flights_old_shape');
        Schema::drop('flights');

        Schema::create('flights_old_shape', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offer_id')->constrained('offers')->cascadeOnDelete();
            $table->string('from_location');
            $table->string('to_location');
            $table->dateTime('departure_time');
            $table->dateTime('arrival_time');
            $table->timestamps();
            $table->unique('offer_id', 'flights_offer_id_unique');
        });

        foreach ($rows as $row) {
            DB::table('flights_old_shape')->insert([
                'id' => $row->id,
                'offer_id' => $row->offer_id,
                'from_location' => $row->departure_city,
                'to_location' => $row->arrival_city,
                'departure_time' => $row->departure_at,
                'arrival_time' => $row->arrival_at,
                'created_at' => $row->created_at ?? now(),
                'updated_at' => $row->updated_at ?? now(),
            ]);
        }

        Schema::rename('flights_old_shape', 'flights');

        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE flights AUTO_INCREMENT = '.((int) DB::table('flights')->max('id') + 1));
        }
    }
};
