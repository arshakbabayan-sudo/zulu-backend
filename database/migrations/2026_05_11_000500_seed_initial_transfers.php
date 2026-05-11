<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Seed 6 demo transfer offers (Yerevan-based routes) so the public
     * /transfers page renders real cards. Mirrors the cars/excursions seed
     * pattern: one `offers` row per transfer + one `transfers` row with the
     * full attribute set the discovery normalizer surfaces.
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

        // Defensive cleanup: a prior failed run on prod may have created
        // `offers` rows of type=transfer without matching `transfers` rows
        // (insert is not transactional across the two tables). Remove those
        // so the idempotency check below doesn't skip them as "already exist".
        DB::table('offers')
            ->where('type', 'transfer')
            ->whereNotIn('id', DB::table('transfers')->select('offer_id'))
            ->delete();

        $transfers = [
            [
                'title' => 'EVN Airport → Yerevan downtown · Sedan',
                'price' => 25.00,
                'short' => 'Quick 25-minute airport sedan transfer — meet-and-greet at arrivals, fixed price.',
                'image' => 'https://images.unsplash.com/photo-1494976388531-d1058494cdd8?auto=format&fit=crop&w=1200&q=80',
                'route' => 'EVN Airport → Yerevan',
                'pickup_point' => 'Zvartnots International Airport (EVN)',
                'pickup_city' => 'Yerevan',
                'pickup_point_type' => 'airport',
                'pickup_time' => '00:00:00',
                'dropoff_point' => 'Yerevan downtown hotel',
                'dropoff_city' => 'Yerevan',
                'dropoff_point_type' => 'hotel',
                'distance_km' => 18,
                'duration_min' => 25,
                'category' => 'private',
                'class' => 'sedan',
                'pax' => 3,
                'luggage' => 3,
            ],
            [
                'title' => 'Yerevan → Garni + Geghard · Half-day SUV',
                'price' => 80.00,
                'short' => 'Round-trip SUV with English-speaking driver — wait at each site, 5-hour total.',
                'image' => 'https://images.unsplash.com/photo-1571900785970-3a087df3f23f?auto=format&fit=crop&w=1200&q=80',
                'route' => 'Yerevan → Garni → Geghard → Yerevan',
                'pickup_point' => 'Yerevan hotel pickup',
                'pickup_city' => 'Yerevan',
                'pickup_point_type' => 'hotel',
                'pickup_time' => '09:00:00',
                'dropoff_point' => 'Yerevan hotel drop-off',
                'dropoff_city' => 'Yerevan',
                'dropoff_point_type' => 'hotel',
                'distance_km' => 75,
                'duration_min' => 300,
                'category' => 'private',
                'class' => 'suv',
                'pax' => 4,
                'luggage' => 2,
            ],
            [
                'title' => 'Yerevan → Lake Sevan return · Premium sedan',
                'price' => 90.00,
                'short' => 'Comfortable premium sedan to Sevan — 1.5h drive each way, 2-hour stop included.',
                'image' => 'https://images.unsplash.com/photo-1503376780353-7e6692767b70?auto=format&fit=crop&w=1200&q=80',
                'route' => 'Yerevan → Sevan → Yerevan',
                'pickup_point' => 'Yerevan hotel pickup',
                'pickup_city' => 'Yerevan',
                'pickup_point_type' => 'hotel',
                'pickup_time' => '08:30:00',
                'dropoff_point' => 'Yerevan hotel drop-off',
                'dropoff_city' => 'Yerevan',
                'dropoff_point_type' => 'hotel',
                'distance_km' => 140,
                'duration_min' => 360,
                'category' => 'private',
                'class' => 'premium_sedan',
                'pax' => 3,
                'luggage' => 3,
            ],
            [
                'title' => 'Yerevan → Tatev day trip · Luxury SUV',
                'price' => 250.00,
                'short' => 'Long full-day luxury SUV to Tatev (250km) with experienced driver — includes Wings of Tatev cable car waiting.',
                'image' => 'https://images.unsplash.com/photo-1555215695-3004980ad54e?auto=format&fit=crop&w=1200&q=80',
                'route' => 'Yerevan → Tatev → Yerevan',
                'pickup_point' => 'Yerevan hotel pickup',
                'pickup_city' => 'Yerevan',
                'pickup_point_type' => 'hotel',
                'pickup_time' => '07:00:00',
                'dropoff_point' => 'Yerevan hotel drop-off',
                'dropoff_city' => 'Yerevan',
                'dropoff_point_type' => 'hotel',
                'distance_km' => 500,
                'duration_min' => 720,
                'category' => 'private',
                'class' => 'luxury_suv',
                'pax' => 4,
                'luggage' => 4,
            ],
            [
                'title' => 'Yerevan → Tbilisi border transfer · Van',
                'price' => 180.00,
                'short' => 'Intercity van to Tbilisi via Sadakhlo border — 6-hour journey, comfort seating for 7 pax.',
                'image' => 'https://images.unsplash.com/photo-1609712409631-13bb2c5da7d2?auto=format&fit=crop&w=1200&q=80',
                'route' => 'Yerevan → Tbilisi (intercity)',
                'pickup_point' => 'Yerevan hotel pickup',
                'pickup_city' => 'Yerevan',
                'pickup_point_type' => 'hotel',
                'pickup_time' => '06:00:00',
                'dropoff_point' => 'Tbilisi downtown',
                'dropoff_city' => 'Tbilisi',
                'dropoff_point_type' => 'hotel',
                'distance_km' => 280,
                'duration_min' => 360,
                'category' => 'private',
                'class' => 'van',
                'pax' => 7,
                'luggage' => 7,
            ],
            [
                'title' => 'Yerevan city group shuttle · Shared 8-pax',
                'price' => 12.00,
                'short' => 'Shared shuttle hopping between Republic Square, Cascade, Vernissage market, GUM — board on/off anywhere.',
                'image' => 'https://images.unsplash.com/photo-1605649461784-bef214a93ab1?auto=format&fit=crop&w=1200&q=80',
                'route' => 'Yerevan city loop',
                'pickup_point' => 'Republic Square',
                'pickup_city' => 'Yerevan',
                'pickup_point_type' => 'landmark',
                'pickup_time' => '10:00:00',
                'dropoff_point' => 'Republic Square',
                'dropoff_city' => 'Yerevan',
                'dropoff_point_type' => 'landmark',
                'distance_km' => 15,
                'duration_min' => 180,
                'category' => 'shared',
                'class' => 'minivan',
                'pax' => 8,
                'luggage' => 4,
            ],
        ];

        foreach ($transfers as $t) {
            if (DB::table('offers')->where('type', 'transfer')->where('title', $t['title'])->exists()) {
                continue;
            }

            DB::transaction(function () use ($t, $companyId, $yerevanLocationId, $now) {
                $offerId = DB::table('offers')->insertGetId([
                    'company_id' => $companyId,
                    'type' => 'transfer',
                    'title' => $t['title'],
                    'price' => $t['price'],
                    'currency' => 'USD',
                    'status' => 'published',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                DB::table('transfers')->insert([
                    'offer_id' => $offerId,
                    'company_id' => $companyId,
                    'transfer_title' => $t['title'],
                    'transfer_type' => 'point_to_point',
                    'pickup_city' => $t['pickup_city'],
                    'origin_location_id' => $yerevanLocationId,
                    'pickup_point_type' => $t['pickup_point_type'],
                    'pickup_point_name' => $t['pickup_point'],
                    'dropoff_city' => $t['dropoff_city'],
                    'destination_location_id' => $yerevanLocationId,
                    'dropoff_point_type' => $t['dropoff_point_type'],
                    'dropoff_point_name' => $t['dropoff_point'],
                    'route_distance_km' => $t['distance_km'],
                    'route_label' => $t['route'],
                    'service_date' => $now->copy()->addDays(7)->toDateString(),
                    'pickup_time' => $t['pickup_time'],
                    'estimated_duration_minutes' => $t['duration_min'],
                    'availability_window_start' => $now->copy()->subDays(1),
                    'availability_window_end' => $now->copy()->addMonths(6),
                    'vehicle_category' => $t['class'],
                    'vehicle_class' => $t['class'],
                    'private_or_shared' => $t['category'],
                    'passenger_capacity' => $t['pax'],
                    'luggage_capacity' => $t['luggage'],
                    'child_seat_available' => true,
                    'accessibility_support' => false,
                    'minimum_passengers' => 1,
                    'maximum_passengers' => $t['pax'],
                    'maximum_luggage' => $t['luggage'],
                    'special_assistance_supported' => false,
                    'pricing_mode' => 'flat_rate',
                    'base_price' => $t['price'],
                    'free_cancellation' => true,
                    'cancellation_policy_type' => 'flexible',
                    'availability_status' => 'available',
                    'bookable' => true,
                    'is_package_eligible' => true,
                    'appears_in_packages' => true,
                    'status' => 'active',
                    'visibility_rule' => 'show_all',
                    'appears_in_web' => true,
                    'appears_in_admin' => true,
                    'appears_in_zulu_admin' => true,
                    'short_description' => $t['short'],
                    'main_image' => $t['image'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            });
        }
    }

    public function down(): void
    {
        $titles = [
            'EVN Airport → Yerevan downtown · Sedan',
            'Yerevan → Garni + Geghard · Half-day SUV',
            'Yerevan → Lake Sevan return · Premium sedan',
            'Yerevan → Tatev day trip · Luxury SUV',
            'Yerevan → Tbilisi border transfer · Van',
            'Yerevan city group shuttle · Shared 8-pax',
        ];

        $offerIds = DB::table('offers')
            ->where('type', 'transfer')
            ->whereIn('title', $titles)
            ->pluck('id')
            ->all();

        if (! empty($offerIds)) {
            DB::table('transfers')->whereIn('offer_id', $offerIds)->delete();
            DB::table('offers')->whereIn('id', $offerIds)->delete();
        }
    }
};
