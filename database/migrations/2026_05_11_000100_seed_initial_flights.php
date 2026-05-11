<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Seed 10 demo flights (5 round-trip routes) that mirror the destination
     * countries already covered by the hotel inventory: Russia, UAE, Egypt,
     * France, Italy. Real airlines and realistic USD prices. Departure dates
     * spread across the next ~12 weeks.
     *
     * Idempotent: skips on re-run if a flight with the same
     * `flight_code_internal` already exists.
     */
    public function up(): void
    {
        $companyId = (int) DB::table('companies')->where('name', 'Aragats Travel Operator LLC')->value('id');
        if ($companyId === 0) {
            $companyId = (int) DB::table('companies')->orderBy('id')->value('id');
        }
        if ($companyId === 0) {
            return; // No operator exists yet — nothing to seed against.
        }

        $cityIds = $this->locateCityIds();
        if (! $cityIds) {
            return; // Required city locations are missing.
        }

        $now = now();

        // departure_at offsets in days from today
        $routes = [
            // 1. Yerevan ↔ Moscow — Aeroflot
            [
                'code' => 'SU1869',
                'airline' => 'Aeroflot',
                'service_type' => 'scheduled',
                'from' => 'EVN', 'from_city' => 'Yerevan', 'from_country' => 'Armenia',
                'from_airport' => 'Zvartnots International Airport',
                'to' => 'SVO', 'to_city' => 'Moscow', 'to_country' => 'Russia',
                'to_airport' => 'Sheremetyevo International Airport',
                'from_loc' => $cityIds['Yerevan'], 'to_loc' => $cityIds['Moscow'],
                'departs_in_days' => 14,
                'depart_hour' => '04:35', 'duration_min' => 195,
                'economy' => 245.00, 'economy_seats' => 156,
                'business' => null, 'business_seats' => 0,
                'tz' => 'Europe/Moscow',
                'image' => 'https://images.unsplash.com/photo-1556388158-158ea5ccacbd?auto=format&fit=crop&w=1200&q=80',
                'short' => 'Direct Aeroflot flight from Yerevan (EVN) to Moscow Sheremetyevo (SVO).',
            ],
            [
                'code' => 'SU1870',
                'airline' => 'Aeroflot',
                'service_type' => 'scheduled',
                'from' => 'SVO', 'from_city' => 'Moscow', 'from_country' => 'Russia',
                'from_airport' => 'Sheremetyevo International Airport',
                'to' => 'EVN', 'to_city' => 'Yerevan', 'to_country' => 'Armenia',
                'to_airport' => 'Zvartnots International Airport',
                'from_loc' => $cityIds['Moscow'], 'to_loc' => $cityIds['Yerevan'],
                'departs_in_days' => 21,
                'depart_hour' => '21:10', 'duration_min' => 205,
                'economy' => 260.00, 'economy_seats' => 156,
                'business' => null, 'business_seats' => 0,
                'tz' => 'Asia/Yerevan',
                'image' => 'https://images.unsplash.com/photo-1556388158-158ea5ccacbd?auto=format&fit=crop&w=1200&q=80',
                'short' => 'Direct Aeroflot return flight Moscow (SVO) → Yerevan (EVN).',
            ],
            // 2. Yerevan ↔ Dubai — FlyOne Armenia
            [
                'code' => '3F721',
                'airline' => 'FlyOne Armenia',
                'service_type' => 'scheduled',
                'from' => 'EVN', 'from_city' => 'Yerevan', 'from_country' => 'Armenia',
                'from_airport' => 'Zvartnots International Airport',
                'to' => 'DXB', 'to_city' => 'Dubai', 'to_country' => 'United Arab Emirates',
                'to_airport' => 'Dubai International Airport',
                'from_loc' => $cityIds['Yerevan'], 'to_loc' => $cityIds['Dubai'],
                'departs_in_days' => 28,
                'depart_hour' => '11:50', 'duration_min' => 175,
                'economy' => 365.00, 'economy_seats' => 180,
                'business' => 920.00, 'business_seats' => 12,
                'tz' => 'Asia/Dubai',
                'image' => 'https://images.unsplash.com/photo-1518684079-3c830dcef090?auto=format&fit=crop&w=1200&q=80',
                'short' => 'FlyOne Armenia direct flight Yerevan (EVN) → Dubai International (DXB).',
            ],
            [
                'code' => '3F722',
                'airline' => 'FlyOne Armenia',
                'service_type' => 'scheduled',
                'from' => 'DXB', 'from_city' => 'Dubai', 'from_country' => 'United Arab Emirates',
                'from_airport' => 'Dubai International Airport',
                'to' => 'EVN', 'to_city' => 'Yerevan', 'to_country' => 'Armenia',
                'to_airport' => 'Zvartnots International Airport',
                'from_loc' => $cityIds['Dubai'], 'to_loc' => $cityIds['Yerevan'],
                'departs_in_days' => 35,
                'depart_hour' => '15:30', 'duration_min' => 185,
                'economy' => 395.00, 'economy_seats' => 180,
                'business' => 950.00, 'business_seats' => 12,
                'tz' => 'Asia/Yerevan',
                'image' => 'https://images.unsplash.com/photo-1518684079-3c830dcef090?auto=format&fit=crop&w=1200&q=80',
                'short' => 'FlyOne Armenia direct return Dubai (DXB) → Yerevan (EVN).',
            ],
            // 3. Yerevan ↔ Hurghada — Wizz Air charter
            [
                'code' => 'W6362',
                'airline' => 'Wizz Air',
                'service_type' => 'charter',
                'from' => 'EVN', 'from_city' => 'Yerevan', 'from_country' => 'Armenia',
                'from_airport' => 'Zvartnots International Airport',
                'to' => 'HRG', 'to_city' => 'Hurghada', 'to_country' => 'Egypt',
                'to_airport' => 'Hurghada International Airport',
                'from_loc' => $cityIds['Yerevan'], 'to_loc' => $cityIds['Hurghada'],
                'departs_in_days' => 42,
                'depart_hour' => '08:15', 'duration_min' => 240,
                'economy' => 415.00, 'economy_seats' => 220,
                'business' => null, 'business_seats' => 0,
                'tz' => 'Africa/Cairo',
                'image' => 'https://images.unsplash.com/photo-1572252442-9d2a4c7e3e87?auto=format&fit=crop&w=1200&q=80',
                'short' => 'Wizz Air seasonal charter from Yerevan (EVN) to Hurghada (HRG) — Red Sea resorts.',
            ],
            [
                'code' => 'W6363',
                'airline' => 'Wizz Air',
                'service_type' => 'charter',
                'from' => 'HRG', 'from_city' => 'Hurghada', 'from_country' => 'Egypt',
                'from_airport' => 'Hurghada International Airport',
                'to' => 'EVN', 'to_city' => 'Yerevan', 'to_country' => 'Armenia',
                'to_airport' => 'Zvartnots International Airport',
                'from_loc' => $cityIds['Hurghada'], 'to_loc' => $cityIds['Yerevan'],
                'departs_in_days' => 49,
                'depart_hour' => '13:40', 'duration_min' => 255,
                'economy' => 430.00, 'economy_seats' => 220,
                'business' => null, 'business_seats' => 0,
                'tz' => 'Asia/Yerevan',
                'image' => 'https://images.unsplash.com/photo-1572252442-9d2a4c7e3e87?auto=format&fit=crop&w=1200&q=80',
                'short' => 'Wizz Air charter return Hurghada (HRG) → Yerevan (EVN).',
            ],
            // 4. Yerevan ↔ Paris — Wizz Air
            [
                'code' => 'W64101',
                'airline' => 'Wizz Air',
                'service_type' => 'scheduled',
                'from' => 'EVN', 'from_city' => 'Yerevan', 'from_country' => 'Armenia',
                'from_airport' => 'Zvartnots International Airport',
                'to' => 'CDG', 'to_city' => 'Paris', 'to_country' => 'France',
                'to_airport' => 'Charles de Gaulle Airport',
                'from_loc' => $cityIds['Yerevan'], 'to_loc' => $cityIds['Paris'],
                'departs_in_days' => 56,
                'depart_hour' => '02:25', 'duration_min' => 290,
                'economy' => 535.00, 'economy_seats' => 230,
                'business' => 1180.00, 'business_seats' => 16,
                'tz' => 'Europe/Paris',
                'image' => 'https://images.unsplash.com/photo-1502602898657-3e91760cbb34?auto=format&fit=crop&w=1200&q=80',
                'short' => 'Wizz Air direct Yerevan (EVN) → Paris Charles de Gaulle (CDG).',
            ],
            [
                'code' => 'W64102',
                'airline' => 'Wizz Air',
                'service_type' => 'scheduled',
                'from' => 'CDG', 'from_city' => 'Paris', 'from_country' => 'France',
                'from_airport' => 'Charles de Gaulle Airport',
                'to' => 'EVN', 'to_city' => 'Yerevan', 'to_country' => 'Armenia',
                'to_airport' => 'Zvartnots International Airport',
                'from_loc' => $cityIds['Paris'], 'to_loc' => $cityIds['Yerevan'],
                'departs_in_days' => 63,
                'depart_hour' => '12:10', 'duration_min' => 300,
                'economy' => 560.00, 'economy_seats' => 230,
                'business' => 1220.00, 'business_seats' => 16,
                'tz' => 'Asia/Yerevan',
                'image' => 'https://images.unsplash.com/photo-1502602898657-3e91760cbb34?auto=format&fit=crop&w=1200&q=80',
                'short' => 'Wizz Air return Paris (CDG) → Yerevan (EVN).',
            ],
            // 5. Yerevan ↔ Rome — FlyOne
            [
                'code' => '3F505',
                'airline' => 'FlyOne Armenia',
                'service_type' => 'scheduled',
                'from' => 'EVN', 'from_city' => 'Yerevan', 'from_country' => 'Armenia',
                'from_airport' => 'Zvartnots International Airport',
                'to' => 'FCO', 'to_city' => 'Rome', 'to_country' => 'Italy',
                'to_airport' => 'Leonardo da Vinci–Fiumicino Airport',
                'from_loc' => $cityIds['Yerevan'], 'to_loc' => $cityIds['Rome'],
                'departs_in_days' => 70,
                'depart_hour' => '09:30', 'duration_min' => 270,
                'economy' => 475.00, 'economy_seats' => 174,
                'business' => null, 'business_seats' => 0,
                'tz' => 'Europe/Rome',
                'image' => 'https://images.unsplash.com/photo-1525874684015-58379d421a52?auto=format&fit=crop&w=1200&q=80',
                'short' => 'FlyOne Armenia Yerevan (EVN) → Rome Fiumicino (FCO).',
            ],
            [
                'code' => '3F506',
                'airline' => 'FlyOne Armenia',
                'service_type' => 'scheduled',
                'from' => 'FCO', 'from_city' => 'Rome', 'from_country' => 'Italy',
                'from_airport' => 'Leonardo da Vinci–Fiumicino Airport',
                'to' => 'EVN', 'to_city' => 'Yerevan', 'to_country' => 'Armenia',
                'to_airport' => 'Zvartnots International Airport',
                'from_loc' => $cityIds['Rome'], 'to_loc' => $cityIds['Yerevan'],
                'departs_in_days' => 77,
                'depart_hour' => '17:55', 'duration_min' => 280,
                'economy' => 495.00, 'economy_seats' => 174,
                'business' => null, 'business_seats' => 0,
                'tz' => 'Asia/Yerevan',
                'image' => 'https://images.unsplash.com/photo-1525874684015-58379d421a52?auto=format&fit=crop&w=1200&q=80',
                'short' => 'FlyOne Armenia return Rome (FCO) → Yerevan (EVN).',
            ],
        ];

        foreach ($routes as $r) {
            // Skip if already seeded (idempotent).
            if (DB::table('flights')->where('flight_code_internal', $r['code'])->exists()) {
                continue;
            }

            $departureAt = $now->copy()->addDays($r['departs_in_days']);
            $hm = explode(':', $r['depart_hour']);
            $departureAt->setTime((int) $hm[0], (int) $hm[1], 0);
            $arrivalAt = $departureAt->copy()->addMinutes($r['duration_min']);

            $offerId = DB::table('offers')->insertGetId([
                'company_id' => $companyId,
                'type' => 'flight',
                'title' => "{$r['airline']} · {$r['from']} → {$r['to']} ({$r['code']})",
                'price' => $r['economy'],
                'currency' => 'USD',
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $flightId = DB::table('flights')->insertGetId([
                'offer_id' => $offerId,
                'company_id' => $companyId,
                'flight_code_internal' => $r['code'],
                'service_type' => $r['service_type'],
                // Country columns were dropped in the 2026-04 location-cleanup —
                // departure_city / arrival_city stay as denormalized labels and
                // departure_location_id / arrival_location_id carry the FK truth.
                'departure_city' => $r['from_city'],
                'departure_airport' => $r['from_airport'],
                'departure_airport_code' => $r['from'],
                'arrival_city' => $r['to_city'],
                'arrival_airport' => $r['to_airport'],
                'arrival_airport_code' => $r['to'],
                'departure_at' => $departureAt,
                'arrival_at' => $arrivalAt,
                'duration_minutes' => $r['duration_min'],
                'timezone_context' => $r['tz'],
                'connection_type' => 'direct',
                'stops_count' => 0,
                'cabin_class' => 'economy',
                'seat_capacity_total' => $r['economy_seats'],
                'seat_capacity_available' => $r['economy_seats'],
                'seat_map_available' => false,
                'adult_age_from' => 12,
                'child_age_from' => 2,
                'child_age_to' => 11,
                'infant_age_from' => 0,
                'infant_age_to' => 1,
                'adult_price' => $r['economy'],
                'child_price' => round($r['economy'] * 0.75, 2),
                'infant_price' => round($r['economy'] * 0.1, 2),
                'hand_baggage_included' => true,
                'hand_baggage_weight' => '8kg',
                'checked_baggage_included' => $r['service_type'] === 'charter',
                'checked_baggage_weight' => $r['service_type'] === 'charter' ? '20kg' : null,
                'extra_baggage_allowed' => true,
                'reservation_allowed' => true,
                'online_checkin_allowed' => true,
                'airport_checkin_allowed' => true,
                'cancellation_policy_type' => 'partially_refundable',
                'change_policy_type' => 'paid_change',
                'is_package_eligible' => true,
                'status' => 'active',
                'visibility_rule' => 'show_all',
                'appears_in_packages' => true,
                'appears_in_web' => true,
                'appears_in_admin' => true,
                'appears_in_zulu_admin' => true,
                'location_id' => $r['from_loc'],
                'departure_location_id' => $r['from_loc'],
                'arrival_location_id' => $r['to_loc'],
                'short_description' => $r['short'],
                'main_image' => $r['image'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            // Economy cabin
            DB::table('flight_cabins')->insert([
                'flight_id' => $flightId,
                'cabin_class' => 'economy',
                'seat_capacity_total' => $r['economy_seats'],
                'seat_capacity_available' => $r['economy_seats'],
                'adult_price' => $r['economy'],
                'child_price' => round($r['economy'] * 0.75, 2),
                'infant_price' => round($r['economy'] * 0.1, 2),
                'hand_baggage_included' => true,
                'hand_baggage_weight' => '8kg',
                'checked_baggage_included' => $r['service_type'] === 'charter',
                'checked_baggage_weight' => $r['service_type'] === 'charter' ? '20kg' : null,
                'extra_baggage_allowed' => true,
                'fare_family' => 'Standard Economy',
                'seat_map_available' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            // Business cabin where defined
            if ($r['business'] !== null) {
                DB::table('flight_cabins')->insert([
                    'flight_id' => $flightId,
                    'cabin_class' => 'business',
                    'seat_capacity_total' => $r['business_seats'],
                    'seat_capacity_available' => $r['business_seats'],
                    'adult_price' => $r['business'],
                    'child_price' => round($r['business'] * 0.85, 2),
                    'infant_price' => round($r['business'] * 0.15, 2),
                    'hand_baggage_included' => true,
                    'hand_baggage_weight' => '12kg',
                    'checked_baggage_included' => true,
                    'checked_baggage_weight' => '32kg',
                    'extra_baggage_allowed' => true,
                    'fare_family' => 'Business Flex',
                    'seat_map_available' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        $codes = [
            'SU1869', 'SU1870',
            '3F721', '3F722',
            'W6362', 'W6363',
            'W64101', 'W64102',
            '3F505', '3F506',
        ];

        $offerIds = DB::table('flights')
            ->whereIn('flight_code_internal', $codes)
            ->pluck('offer_id')
            ->all();

        $flightIds = DB::table('flights')
            ->whereIn('flight_code_internal', $codes)
            ->pluck('id')
            ->all();

        if (! empty($flightIds)) {
            DB::table('flight_cabins')->whereIn('flight_id', $flightIds)->delete();
            DB::table('flights')->whereIn('id', $flightIds)->delete();
        }
        if (! empty($offerIds)) {
            DB::table('offers')->whereIn('id', $offerIds)->delete();
        }
    }

    /**
     * @return array<string, int>|null
     */
    private function locateCityIds(): ?array
    {
        $rows = DB::table('locations')
            ->whereIn('name', ['Yerevan', 'Moscow', 'Dubai', 'Hurghada', 'Paris', 'Rome'])
            ->where('type', 'city')
            ->get(['id', 'name']);

        $map = [];
        foreach ($rows as $row) {
            $map[(string) $row->name] = (int) $row->id;
        }

        $needed = ['Yerevan', 'Moscow', 'Dubai', 'Hurghada', 'Paris', 'Rome'];
        foreach ($needed as $n) {
            if (! isset($map[$n])) {
                return null;
            }
        }

        return $map;
    }
};
