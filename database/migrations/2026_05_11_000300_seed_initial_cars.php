<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Seed 6 demo car rental offers (Yerevan-based fleet) so the public /cars
     * page renders real cards instead of an empty state. Mirrors the flights
     * seed (`2026_05_11_000100_seed_initial_flights.php`) in spirit: one
     * `offers` row per car + one `cars` row with the full vehicle attributes
     * the discovery normalizer exposes.
     *
     * Idempotent: skips on re-run when an offer with the same internal title
     * already exists.
     */
    public function up(): void
    {
        $companyId = (int) DB::table('companies')->where('name', 'Aragats Travel Operator LLC')->value('id');
        if ($companyId === 0) {
            $companyId = (int) DB::table('companies')->orderBy('id')->value('id');
        }
        if ($companyId === 0) {
            return;
        }

        $yerevanLocationId = (int) DB::table('locations')
            ->where('name', 'Yerevan')
            ->where('type', 'city')
            ->value('id');
        if ($yerevanLocationId === 0) {
            return;
        }

        $now = now();

        $cars = [
            [
                'title' => 'Toyota Corolla 2024 · Economy · Yerevan',
                'price' => 45.00,
                'short' => 'Reliable 5-seat sedan with automatic transmission — great city + airport runs.',
                'image' => 'https://images.unsplash.com/photo-1503376780353-7e6692767b70?auto=format&fit=crop&w=1200&q=80',
                'vehicle_class' => 'economy',
                'vehicle_type' => 'sedan',
                'brand' => 'Toyota',
                'model' => 'Corolla',
                'year' => 2024,
                'transmission' => 'automatic',
                'fuel' => 'petrol',
                'category' => 'Economy',
                'seats' => 5,
                'suitcases' => 2,
                'small_bag' => 2,
            ],
            [
                'title' => 'Hyundai Tucson 2024 · SUV · Yerevan',
                'price' => 72.00,
                'short' => 'Compact SUV with 4WD — best pick for road trips to Garni, Sevan, Tatev.',
                'image' => 'https://images.unsplash.com/photo-1612544448445-b8232cff3b6c?auto=format&fit=crop&w=1200&q=80',
                'vehicle_class' => 'suv',
                'vehicle_type' => 'suv',
                'brand' => 'Hyundai',
                'model' => 'Tucson',
                'year' => 2024,
                'transmission' => 'automatic',
                'fuel' => 'petrol',
                'category' => 'Compact SUV',
                'seats' => 5,
                'suitcases' => 3,
                'small_bag' => 2,
            ],
            [
                'title' => 'Mercedes-Benz E-Class 2023 · Premium · Yerevan',
                'price' => 145.00,
                'short' => 'Executive premium sedan — leather interior, panoramic roof, advanced driver assists.',
                'image' => 'https://images.unsplash.com/photo-1617531653332-bd46c24f2068?auto=format&fit=crop&w=1200&q=80',
                'vehicle_class' => 'premium',
                'vehicle_type' => 'sedan',
                'brand' => 'Mercedes-Benz',
                'model' => 'E-Class',
                'year' => 2023,
                'transmission' => 'automatic',
                'fuel' => 'diesel',
                'category' => 'Premium',
                'seats' => 5,
                'suitcases' => 3,
                'small_bag' => 2,
            ],
            [
                'title' => 'Toyota Hiace 2023 · Minivan · Yerevan',
                'price' => 95.00,
                'short' => '8-seat minivan with extended luggage space — ideal for family or group bookings.',
                'image' => 'https://images.unsplash.com/photo-1609712409631-13bb2c5da7d2?auto=format&fit=crop&w=1200&q=80',
                'vehicle_class' => 'van',
                'vehicle_type' => 'minivan',
                'brand' => 'Toyota',
                'model' => 'Hiace',
                'year' => 2023,
                'transmission' => 'automatic',
                'fuel' => 'diesel',
                'category' => 'Minivan',
                'seats' => 8,
                'suitcases' => 6,
                'small_bag' => 4,
            ],
            [
                'title' => 'Kia Picanto 2024 · Mini · Yerevan',
                'price' => 32.00,
                'short' => 'Compact 4-seater with manual transmission — cheapest way to get around town.',
                'image' => 'https://images.unsplash.com/photo-1583121274602-3e2820c69888?auto=format&fit=crop&w=1200&q=80',
                'vehicle_class' => 'mini',
                'vehicle_type' => 'hatchback',
                'brand' => 'Kia',
                'model' => 'Picanto',
                'year' => 2024,
                'transmission' => 'manual',
                'fuel' => 'petrol',
                'category' => 'Mini',
                'seats' => 4,
                'suitcases' => 1,
                'small_bag' => 1,
            ],
            [
                'title' => 'BMW X5 2024 · Luxury SUV · Yerevan',
                'price' => 195.00,
                'short' => 'Luxury 7-seat SUV — heated leather seats, premium audio, all-wheel drive.',
                'image' => 'https://images.unsplash.com/photo-1555215695-3004980ad54e?auto=format&fit=crop&w=1200&q=80',
                'vehicle_class' => 'luxury',
                'vehicle_type' => 'suv',
                'brand' => 'BMW',
                'model' => 'X5',
                'year' => 2024,
                'transmission' => 'automatic',
                'fuel' => 'petrol',
                'category' => 'Luxury',
                'seats' => 7,
                'suitcases' => 4,
                'small_bag' => 3,
            ],
        ];

        foreach ($cars as $c) {
            if (DB::table('offers')->where('type', 'car')->where('title', $c['title'])->exists()) {
                continue;
            }

            $offerId = DB::table('offers')->insertGetId([
                'company_id' => $companyId,
                'type' => 'car',
                'title' => $c['title'],
                'price' => $c['price'],
                'currency' => 'USD',
                'status' => 'published',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('cars')->insert([
                'offer_id' => $offerId,
                'pickup_location' => 'Yerevan',
                'dropoff_location' => 'Yerevan',
                'vehicle_class' => $c['vehicle_class'],
                'vehicle_type' => $c['vehicle_type'],
                'brand' => $c['brand'],
                'model' => $c['model'],
                'year' => $c['year'],
                'transmission_type' => $c['transmission'],
                'fuel_type' => $c['fuel'],
                'category' => $c['category'],
                'seats' => $c['seats'],
                'suitcases' => $c['suitcases'],
                'small_bag' => $c['small_bag'],
                'availability_window_start' => $now->copy()->subDays(1),
                'availability_window_end' => $now->copy()->addMonths(6),
                'pricing_mode' => 'per_day',
                'base_price' => $c['price'],
                'status' => 'active',
                'availability_status' => 'available',
                'visibility_rule' => 'show_all',
                'appears_in_web' => true,
                'appears_in_admin' => true,
                'appears_in_zulu_admin' => true,
                'short_description' => $c['short'],
                'main_image' => $c['image'],
                'location_id' => $yerevanLocationId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        $titles = [
            'Toyota Corolla 2024 · Economy · Yerevan',
            'Hyundai Tucson 2024 · SUV · Yerevan',
            'Mercedes-Benz E-Class 2023 · Premium · Yerevan',
            'Toyota Hiace 2023 · Minivan · Yerevan',
            'Kia Picanto 2024 · Mini · Yerevan',
            'BMW X5 2024 · Luxury SUV · Yerevan',
        ];

        $offerIds = DB::table('offers')
            ->where('type', 'car')
            ->whereIn('title', $titles)
            ->pluck('id')
            ->all();

        if (! empty($offerIds)) {
            DB::table('cars')->whereIn('offer_id', $offerIds)->delete();
            DB::table('offers')->whereIn('id', $offerIds)->delete();
        }
    }
};
