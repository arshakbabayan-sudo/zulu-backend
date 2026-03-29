<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 2 Step 2.6 — normalized hotels + hotel_rooms + hotel_room_pricings.
     * Rebuilds legacy minimal `hotels` (location, room_type, capacity) when needed.
     */
    public function up(): void
    {
        if (! Schema::hasTable('hotels')) {
            return;
        }

        if (Schema::hasColumn('hotels', 'hotel_name')) {
            $this->ensureRoomTables();

            return;
        }

        $rows = DB::table('hotels')->orderBy('id')->get();

        Schema::dropIfExists('hotel_room_pricings');
        Schema::dropIfExists('hotel_rooms');
        Schema::drop('hotels');

        Schema::create('hotels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offer_id')->constrained('offers')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('hotel_name');
            $table->string('property_type', 64);
            $table->string('hotel_type', 64);
            $table->unsignedTinyInteger('star_rating')->nullable();
            $table->string('country', 120);
            $table->string('region_or_state')->nullable();
            $table->string('city');
            $table->string('district_or_area')->nullable();
            $table->text('full_address')->nullable();
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->text('short_description')->nullable();
            $table->string('main_image')->nullable();
            $table->time('check_in_time')->nullable();
            $table->time('check_out_time')->nullable();
            $table->string('meal_type', 64);
            $table->boolean('free_wifi')->default(false);
            $table->boolean('parking')->default(false);
            $table->boolean('airport_shuttle')->default(false);
            $table->boolean('indoor_pool')->default(false);
            $table->boolean('outdoor_pool')->default(false);
            $table->boolean('room_service')->default(false);
            $table->boolean('front_desk_24h')->default(false);
            $table->boolean('child_friendly')->default(false);
            $table->boolean('accessibility_support')->default(false);
            $table->boolean('pets_allowed')->default(false);
            $table->boolean('free_cancellation')->default(false);
            $table->string('cancellation_policy_type', 64)->nullable();
            $table->dateTime('cancellation_deadline_at')->nullable();
            $table->boolean('prepayment_required')->default(false);
            $table->string('no_show_policy')->nullable();
            $table->decimal('review_score', 4, 2)->nullable();
            $table->unsignedInteger('review_count')->default(0);
            $table->string('review_label')->nullable();
            $table->string('availability_status', 32)->default('available');
            $table->boolean('bookable')->default(true);
            $table->string('room_inventory_mode', 64)->nullable();
            $table->boolean('is_package_eligible')->default(false);
            $table->string('status', 32)->default('draft');
            $table->timestamps();

            $table->unique('offer_id', 'hotels_offer_id_unique');
            $table->index('company_id', 'hotels_company_id_index');
        });

        Schema::create('hotel_rooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->constrained('hotels')->cascadeOnDelete();
            $table->string('room_type');
            $table->string('room_name');
            $table->unsignedSmallInteger('max_adults');
            $table->unsignedSmallInteger('max_children')->default(0);
            $table->unsignedSmallInteger('max_total_guests');
            $table->string('bed_type')->nullable();
            $table->unsignedSmallInteger('bed_count')->default(1);
            $table->string('room_size')->nullable();
            $table->string('room_view')->nullable();
            $table->boolean('private_bathroom')->default(false);
            $table->boolean('smoking_allowed')->default(false);
            $table->boolean('air_conditioning')->default(false);
            $table->boolean('wifi')->default(false);
            $table->boolean('tv')->default(false);
            $table->boolean('mini_fridge')->default(false);
            $table->boolean('tea_coffee_maker')->default(false);
            $table->boolean('kettle')->default(false);
            $table->boolean('washing_machine')->default(false);
            $table->boolean('soundproofing')->default(false);
            $table->boolean('terrace_or_balcony')->default(false);
            $table->boolean('patio')->default(false);
            $table->boolean('bath')->default(false);
            $table->boolean('shower')->default(false);
            $table->string('view_type')->nullable();
            $table->json('room_images')->nullable();
            $table->unsignedSmallInteger('room_inventory_count')->nullable();
            $table->string('status', 32)->default('active');
            $table->timestamps();

            $table->index('hotel_id', 'hotel_rooms_hotel_id_index');
        });

        Schema::create('hotel_room_pricings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_room_id')->constrained('hotel_rooms')->cascadeOnDelete();
            $table->decimal('price', 12, 2);
            $table->string('currency', 3);
            $table->string('pricing_mode', 32)->default('per_night');
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->unsignedSmallInteger('min_nights')->nullable();
            $table->string('status', 32)->default('active');
            $table->timestamps();

            $table->index('hotel_room_id', 'hotel_room_pricings_hotel_room_id_index');
        });

        $now = now();

        foreach ($rows as $row) {
            $offer = DB::table('offers')->where('id', $row->offer_id)->first();
            if ($offer === null) {
                continue;
            }

            $hotelName = $offer->title !== '' && $offer->title !== null
                ? $offer->title
                : 'Legacy hotel #'.$row->id;

            DB::table('hotels')->insert([
                'id' => $row->id,
                'offer_id' => $row->offer_id,
                'company_id' => $offer->company_id,
                'hotel_name' => $hotelName,
                'property_type' => 'hotel',
                'hotel_type' => 'standard',
                'star_rating' => null,
                'country' => 'Unknown',
                'region_or_state' => null,
                'city' => $row->location ?? 'Unknown',
                'district_or_area' => null,
                'full_address' => null,
                'latitude' => null,
                'longitude' => null,
                'short_description' => null,
                'main_image' => null,
                'check_in_time' => null,
                'check_out_time' => null,
                'meal_type' => 'room_only',
                'free_wifi' => false,
                'parking' => false,
                'airport_shuttle' => false,
                'indoor_pool' => false,
                'outdoor_pool' => false,
                'room_service' => false,
                'front_desk_24h' => false,
                'child_friendly' => false,
                'accessibility_support' => false,
                'pets_allowed' => false,
                'free_cancellation' => false,
                'cancellation_policy_type' => null,
                'cancellation_deadline_at' => null,
                'prepayment_required' => false,
                'no_show_policy' => null,
                'review_score' => null,
                'review_count' => 0,
                'review_label' => null,
                'availability_status' => 'available',
                'bookable' => true,
                'room_inventory_mode' => null,
                'is_package_eligible' => false,
                'status' => 'draft',
                'created_at' => $row->created_at ?? $now,
                'updated_at' => $row->updated_at ?? $now,
            ]);

            $capacity = max(1, (int) ($row->capacity ?? 1));
            $roomType = $row->room_type !== '' && $row->room_type !== null ? $row->room_type : 'standard';

            $roomId = DB::table('hotel_rooms')->insertGetId([
                'hotel_id' => $row->id,
                'room_type' => $roomType,
                'room_name' => $roomType,
                'max_adults' => $capacity,
                'max_children' => 0,
                'max_total_guests' => $capacity,
                'bed_type' => null,
                'bed_count' => 1,
                'room_size' => null,
                'room_view' => null,
                'private_bathroom' => false,
                'smoking_allowed' => false,
                'air_conditioning' => false,
                'wifi' => false,
                'tv' => false,
                'mini_fridge' => false,
                'tea_coffee_maker' => false,
                'kettle' => false,
                'washing_machine' => false,
                'soundproofing' => false,
                'terrace_or_balcony' => false,
                'patio' => false,
                'bath' => false,
                'shower' => false,
                'view_type' => null,
                'room_images' => null,
                'room_inventory_count' => null,
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $currency = $offer->currency !== null && strlen((string) $offer->currency) === 3
                ? strtoupper((string) $offer->currency)
                : 'USD';

            DB::table('hotel_room_pricings')->insert([
                'hotel_room_id' => $roomId,
                'price' => $offer->price ?? 0,
                'currency' => $currency,
                'pricing_mode' => 'per_night',
                'valid_from' => null,
                'valid_to' => null,
                'min_nights' => null,
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $this->bumpAutoIncrement('hotels');
        $this->bumpAutoIncrement('hotel_rooms');
        $this->bumpAutoIncrement('hotel_room_pricings');
    }

    protected function ensureRoomTables(): void
    {
        if (! Schema::hasTable('hotel_rooms')) {
            Schema::create('hotel_rooms', function (Blueprint $table) {
                $table->id();
                $table->foreignId('hotel_id')->constrained('hotels')->cascadeOnDelete();
                $table->string('room_type');
                $table->string('room_name');
                $table->unsignedSmallInteger('max_adults');
                $table->unsignedSmallInteger('max_children')->default(0);
                $table->unsignedSmallInteger('max_total_guests');
                $table->string('bed_type')->nullable();
                $table->unsignedSmallInteger('bed_count')->default(1);
                $table->string('room_size')->nullable();
                $table->string('room_view')->nullable();
                $table->boolean('private_bathroom')->default(false);
                $table->boolean('smoking_allowed')->default(false);
                $table->boolean('air_conditioning')->default(false);
                $table->boolean('wifi')->default(false);
                $table->boolean('tv')->default(false);
                $table->boolean('mini_fridge')->default(false);
                $table->boolean('tea_coffee_maker')->default(false);
                $table->boolean('kettle')->default(false);
                $table->boolean('washing_machine')->default(false);
                $table->boolean('soundproofing')->default(false);
                $table->boolean('terrace_or_balcony')->default(false);
                $table->boolean('patio')->default(false);
                $table->boolean('bath')->default(false);
                $table->boolean('shower')->default(false);
                $table->string('view_type')->nullable();
                $table->json('room_images')->nullable();
                $table->unsignedSmallInteger('room_inventory_count')->nullable();
                $table->string('status', 32)->default('active');
                $table->timestamps();

                $table->index('hotel_id', 'hotel_rooms_hotel_id_index');
            });
        }

        if (! Schema::hasTable('hotel_room_pricings')) {
            Schema::create('hotel_room_pricings', function (Blueprint $table) {
                $table->id();
                $table->foreignId('hotel_room_id')->constrained('hotel_rooms')->cascadeOnDelete();
                $table->decimal('price', 12, 2);
                $table->string('currency', 3);
                $table->string('pricing_mode', 32)->default('per_night');
                $table->date('valid_from')->nullable();
                $table->date('valid_to')->nullable();
                $table->unsignedSmallInteger('min_nights')->nullable();
                $table->string('status', 32)->default('active');
                $table->timestamps();

                $table->index('hotel_room_id', 'hotel_room_pricings_hotel_room_id_index');
            });
        }
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
        if (! Schema::hasTable('hotels') || ! Schema::hasColumn('hotels', 'hotel_name')) {
            return;
        }

        $hotelRows = DB::table('hotels')->orderBy('id')->get();
        $roomRows = DB::table('hotel_rooms')->orderBy('id')->get()->groupBy('hotel_id');

        Schema::dropIfExists('hotel_room_pricings');
        Schema::dropIfExists('hotel_rooms');
        Schema::drop('hotels');

        Schema::create('hotels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offer_id')->constrained('offers')->cascadeOnDelete();
            $table->string('location');
            $table->string('room_type');
            $table->unsignedInteger('capacity');
            $table->timestamps();
            $table->unique('offer_id', 'hotels_offer_id_unique');
        });

        $now = now();

        foreach ($hotelRows as $h) {
            $first = isset($roomRows[$h->id]) ? $roomRows[$h->id]->first() : null;
            DB::table('hotels')->insert([
                'id' => $h->id,
                'offer_id' => $h->offer_id,
                'location' => $h->city ?? '',
                'room_type' => $first->room_type ?? 'standard',
                'capacity' => $first !== null ? (int) $first->max_total_guests : 1,
                'created_at' => $h->created_at ?? $now,
                'updated_at' => $h->updated_at ?? $now,
            ]);
        }

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            $maxId = (int) DB::table('hotels')->max('id');
            if ($maxId > 0) {
                DB::statement('ALTER TABLE hotels AUTO_INCREMENT = '.($maxId + 1));
            }
        }
    }
};
