<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 2 Step 2.12A — align `transfers` with TRANSFER_MODEL_DEEP_DESIGN.md (schema + backfill).
     */
    public function up(): void
    {
        if (! Schema::hasTable('transfers') || Schema::hasColumn('transfers', 'transfer_title')) {
            return;
        }

        Schema::table('transfers', function (Blueprint $table) {
            $table->string('transfer_title')->nullable()->after('company_id');
            $table->string('pickup_country', 120)->nullable()->after('transfer_type');
            $table->string('pickup_city', 255)->nullable()->after('pickup_country');
            $table->string('pickup_point_type', 32)->nullable()->after('pickup_city');
            $table->text('pickup_point_name')->nullable()->after('pickup_point_type');
            $table->string('dropoff_country', 120)->nullable()->after('pickup_point_name');
            $table->string('dropoff_city', 255)->nullable()->after('dropoff_country');
            $table->string('dropoff_point_type', 32)->nullable()->after('dropoff_city');
            $table->text('dropoff_point_name')->nullable()->after('dropoff_point_type');
            $table->string('route_label')->nullable()->after('dropoff_longitude');
            $table->decimal('route_distance_km', 10, 2)->nullable()->after('route_label');
            $table->date('service_date')->nullable()->after('route_distance_km');
            $table->time('pickup_time')->nullable()->after('service_date');
            $table->unsignedInteger('estimated_duration_minutes')->nullable()->after('pickup_time');
            $table->dateTime('availability_window_start')->nullable()->after('estimated_duration_minutes');
            $table->dateTime('availability_window_end')->nullable()->after('availability_window_start');
            $table->string('vehicle_category', 32)->nullable()->after('availability_window_end');
            $table->string('private_or_shared', 16)->nullable()->after('vehicle_class');
            $table->boolean('child_seat_available')->default(false)->after('luggage_capacity');
            $table->boolean('accessibility_support')->default(false)->after('child_seat_available');
            $table->unsignedSmallInteger('minimum_passengers')->nullable()->after('accessibility_support');
            $table->unsignedSmallInteger('maximum_passengers')->nullable()->after('minimum_passengers');
            $table->unsignedSmallInteger('maximum_luggage')->nullable()->after('maximum_passengers');
            $table->string('child_seat_required_rule', 64)->nullable()->after('maximum_luggage');
            $table->boolean('special_assistance_supported')->default(false)->after('child_seat_required_rule');
            $table->string('pricing_mode', 32)->nullable()->after('special_assistance_supported');
            $table->decimal('base_price', 12, 2)->nullable()->after('pricing_mode');
            $table->boolean('bookable')->default(true)->after('free_cancellation');
        });

        $rows = DB::table('transfers')->orderBy('id')->get();

        foreach ($rows as $row) {
            $offer = DB::table('offers')->where('id', $row->offer_id)->first();
            $basePrice = $offer !== null && $offer->price !== null ? (float) $offer->price : 0.0;

            $mapType = static function (string $t): string {
                return match ($t) {
                    'private' => 'private_transfer',
                    'shared' => 'shared_transfer',
                    'shuttle' => 'shared_transfer',
                    default => in_array($t, [
                        'airport_transfer', 'hotel_transfer', 'city_transfer', 'private_transfer',
                        'shared_transfer', 'intercity_transfer',
                    ], true) ? $t : 'private_transfer',
                };
            };

            $mapVehicle = static function (string $v): string {
                $v = strtolower(trim($v));

                return match ($v) {
                    'sedan' => 'sedan',
                    'van' => 'minivan',
                    'minibus' => 'minibus',
                    'bus' => 'bus',
                    'suv' => 'suv',
                    'luxury_car' => 'luxury_car',
                    default => in_array($v, ['sedan', 'suv', 'minivan', 'minibus', 'bus', 'luxury_car'], true) ? $v : 'sedan',
                };
            };

            $privateOrShared = match ((string) ($row->transfer_type ?? 'private')) {
                'shared', 'shuttle' => 'shared',
                default => 'private',
            };

            $policy = $row->cancellation_policy_type !== null && $row->cancellation_policy_type !== ''
                ? (string) $row->cancellation_policy_type
                : 'non_refundable';

            $estDuration = $row->duration_minutes !== null ? (int) $row->duration_minutes : 60;

            DB::table('transfers')->where('id', $row->id)->update([
                'transfer_title' => (string) $row->transfer_name,
                'pickup_country' => 'Unknown',
                'pickup_city' => 'Unknown',
                'pickup_point_type' => (string) $row->pickup_type,
                'pickup_point_name' => (string) $row->pickup_location,
                'dropoff_country' => 'Unknown',
                'dropoff_city' => 'Unknown',
                'dropoff_point_type' => (string) $row->dropoff_type,
                'dropoff_point_name' => (string) $row->dropoff_location,
                'route_label' => null,
                'route_distance_km' => $row->distance_km,
                'service_date' => now()->toDateString(),
                'pickup_time' => '09:00:00',
                'estimated_duration_minutes' => $estDuration,
                'availability_window_start' => null,
                'availability_window_end' => null,
                'vehicle_category' => $mapVehicle((string) $row->vehicle_type),
                'transfer_type' => $mapType((string) $row->transfer_type),
                'private_or_shared' => $privateOrShared,
                'child_seat_available' => false,
                'accessibility_support' => false,
                'minimum_passengers' => 1,
                'maximum_passengers' => max(1, (int) $row->passenger_capacity),
                'maximum_luggage' => $row->luggage_capacity !== null ? (int) $row->luggage_capacity : null,
                'child_seat_required_rule' => null,
                'special_assistance_supported' => false,
                'pricing_mode' => 'per_vehicle',
                'base_price' => $basePrice,
                'bookable' => true,
                'cancellation_policy_type' => $policy,
            ]);
        }

        Schema::table('transfers', function (Blueprint $table) {
            $table->dropColumn([
                'transfer_name',
                'vehicle_type',
                'pickup_type',
                'dropoff_type',
                'pickup_location',
                'dropoff_location',
                'distance_km',
                'duration_minutes',
                'meet_and_greet',
                'free_wait_time_minutes',
            ]);
        });

        Schema::table('transfers', function (Blueprint $table) {
            $table->string('transfer_title')->nullable(false)->change();
            $table->string('pickup_country', 120)->nullable(false)->change();
            $table->string('pickup_city', 255)->nullable(false)->change();
            $table->string('pickup_point_type', 32)->nullable(false)->change();
            $table->text('pickup_point_name')->nullable(false)->change();
            $table->string('dropoff_country', 120)->nullable(false)->change();
            $table->string('dropoff_city', 255)->nullable(false)->change();
            $table->string('dropoff_point_type', 32)->nullable(false)->change();
            $table->text('dropoff_point_name')->nullable(false)->change();
            $table->date('service_date')->nullable(false)->change();
            $table->time('pickup_time')->nullable(false)->change();
            $table->unsignedInteger('estimated_duration_minutes')->nullable(false)->change();
            $table->string('vehicle_category', 32)->nullable(false)->change();
            $table->unsignedSmallInteger('minimum_passengers')->nullable(false)->change();
            $table->unsignedSmallInteger('maximum_passengers')->nullable(false)->change();
            $table->string('pricing_mode', 32)->nullable(false)->change();
            $table->decimal('base_price', 12, 2)->nullable(false)->change();
            $table->string('cancellation_policy_type', 64)->nullable(false)->change();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('transfers') || ! Schema::hasColumn('transfers', 'transfer_title')) {
            return;
        }

        Schema::table('transfers', function (Blueprint $table) {
            $table->string('transfer_name')->nullable();
            $table->string('vehicle_type', 32)->nullable();
            $table->string('pickup_type', 32)->nullable();
            $table->string('dropoff_type', 32)->nullable();
            $table->text('pickup_location')->nullable();
            $table->text('dropoff_location')->nullable();
            $table->decimal('distance_km', 10, 2)->nullable();
            $table->unsignedInteger('duration_minutes')->nullable();
            $table->boolean('meet_and_greet')->default(false);
            $table->unsignedSmallInteger('free_wait_time_minutes')->nullable();
        });

        $rows = DB::table('transfers')->orderBy('id')->get();

        foreach ($rows as $row) {
            $revType = static function (string $t): string {
                return match ($t) {
                    'private_transfer' => 'private',
                    'shared_transfer', 'intercity_transfer', 'city_transfer' => 'shared',
                    default => 'private',
                };
            };

            $revVehicle = static function (string $v): string {
                return match ($v) {
                    'minivan' => 'van',
                    default => $v,
                };
            };

            DB::table('transfers')->where('id', $row->id)->update([
                'transfer_name' => $row->transfer_title,
                'vehicle_type' => $revVehicle((string) $row->vehicle_category),
                'pickup_type' => $row->pickup_point_type,
                'dropoff_type' => $row->dropoff_point_type,
                'pickup_location' => $row->pickup_point_name,
                'dropoff_location' => $row->dropoff_point_name,
                'distance_km' => $row->route_distance_km,
                'duration_minutes' => $row->estimated_duration_minutes,
                'meet_and_greet' => false,
                'free_wait_time_minutes' => null,
                'transfer_type' => $revType((string) $row->transfer_type),
            ]);
        }

        Schema::table('transfers', function (Blueprint $table) {
            $table->dropColumn([
                'transfer_title',
                'pickup_country',
                'pickup_city',
                'pickup_point_type',
                'pickup_point_name',
                'dropoff_country',
                'dropoff_city',
                'dropoff_point_type',
                'dropoff_point_name',
                'route_label',
                'route_distance_km',
                'service_date',
                'pickup_time',
                'estimated_duration_minutes',
                'availability_window_start',
                'availability_window_end',
                'vehicle_category',
                'private_or_shared',
                'child_seat_available',
                'accessibility_support',
                'minimum_passengers',
                'maximum_passengers',
                'maximum_luggage',
                'child_seat_required_rule',
                'special_assistance_supported',
                'pricing_mode',
                'base_price',
                'bookable',
            ]);
        });

        Schema::table('transfers', function (Blueprint $table) {
            $table->string('transfer_name')->nullable(false)->change();
            $table->string('transfer_type', 32)->nullable(false)->change();
            $table->string('vehicle_type', 32)->nullable(false)->change();
            $table->string('pickup_type', 32)->nullable(false)->change();
            $table->string('dropoff_type', 32)->nullable(false)->change();
            $table->text('pickup_location')->nullable(false)->change();
            $table->text('dropoff_location')->nullable(false)->change();
        });
    }
};
