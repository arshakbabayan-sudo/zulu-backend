<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Seed 5 demo excursion offers (Armenia-based tours) so the public
     * /excursions page renders real cards instead of an empty state.
     * Mirrors the cars + flights seed pattern.
     *
     * Idempotent — skips on re-run when a tour with the same offer title
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

        $tours = [
            [
                'title' => 'Garni Temple + Geghard Monastery · Half-day · Yerevan',
                'price' => 38.00,
                'tour' => 'Garni & Geghard half-day classic',
                'overview' => 'Visit Armenia\'s only standing Greco-Roman temple at Garni and the medieval rock-hewn Geghard Monastery — a UNESCO World Heritage site. Includes traditional lavash baking demo.',
                'short' => 'Half-day classic: Garni Roman temple + UNESCO-listed Geghard rock monastery.',
                'image' => 'https://images.unsplash.com/photo-1601128814389-7c0bdf3ec47f?auto=format&fit=crop&w=1200&q=80',
                'duration' => '5 hours',
                'general_category' => 'cultural',
                'category' => 'historic_sites',
                'excursion_type' => 'half_day',
                'group_size' => 15,
                'ticket_max' => 15,
                'language' => 'English',
                'starts_offset_days' => 7,
                'starts_hour' => '09:30',
                'ends_hour' => '14:30',
            ],
            [
                'title' => 'Lake Sevan + Sevanavank · Full day · Yerevan',
                'price' => 55.00,
                'tour' => 'Lake Sevan + Sevanavank full-day',
                'overview' => 'Drive to the "Pearl of Armenia" — Lake Sevan at 1900m altitude. Climb to the 9th-century Sevanavank monastery on the peninsula for panoramic lake views. Includes traditional lakeside lunch.',
                'short' => 'Drive to Lake Sevan (1900m), climb to Sevanavank monastery, lunch on the peninsula.',
                'image' => 'https://images.unsplash.com/photo-1593194632060-1ac49f8f5bcb?auto=format&fit=crop&w=1200&q=80',
                'duration' => '8 hours',
                'general_category' => 'nature',
                'category' => 'lake_tour',
                'excursion_type' => 'full_day',
                'group_size' => 20,
                'ticket_max' => 20,
                'language' => 'English',
                'starts_offset_days' => 10,
                'starts_hour' => '08:00',
                'ends_hour' => '16:00',
            ],
            [
                'title' => 'Khor Virap + Areni Winery + Noravank · Full day · Yerevan',
                'price' => 68.00,
                'tour' => 'Khor Virap + Areni wine + Noravank',
                'overview' => 'Iconic Mount Ararat views from Khor Virap monastery. Wine tasting at Areni — the world\'s oldest known winemaking site (6100 BCE). Continue to the dramatic red-cliff Noravank monastery.',
                'short' => 'Mount Ararat views, world\'s oldest winery (6100 BCE), red-cliff Noravank — full day.',
                'image' => 'https://images.unsplash.com/photo-1568667256549-094345857637?auto=format&fit=crop&w=1200&q=80',
                'duration' => '10 hours',
                'general_category' => 'cultural',
                'category' => 'wine_tour',
                'excursion_type' => 'full_day',
                'group_size' => 18,
                'ticket_max' => 18,
                'language' => 'English',
                'starts_offset_days' => 14,
                'starts_hour' => '08:30',
                'ends_hour' => '18:30',
            ],
            [
                'title' => 'Tatev Monastery + "Wings of Tatev" cable car · Full day',
                'price' => 95.00,
                'tour' => 'Tatev Wings cable car + monastery',
                'overview' => 'Ride the Wings of Tatev — the world\'s longest reversible aerial tramway (5.7km) — to the 9th-century Tatev Monastery perched on a basalt cliff. Stop at the Devil\'s Bridge natural rock formation.',
                'short' => 'Wings of Tatev — world\'s longest cable car (5.7km) — + 9th-century cliff-edge monastery.',
                'image' => 'https://images.unsplash.com/photo-1571051568900-12127c8f31a4?auto=format&fit=crop&w=1200&q=80',
                'duration' => '12 hours',
                'general_category' => 'adventure',
                'category' => 'cable_car_tour',
                'excursion_type' => 'full_day',
                'group_size' => 14,
                'ticket_max' => 14,
                'language' => 'English',
                'starts_offset_days' => 21,
                'starts_hour' => '07:00',
                'ends_hour' => '19:00',
            ],
            [
                'title' => 'Yerevan city walking tour + Cascade + brandy factory',
                'price' => 28.00,
                'tour' => 'Yerevan city + brandy half-day',
                'overview' => 'Walk the central Republic Square, Cascade Complex art galleries, and the Armenian Genocide Memorial. End with a guided tasting at the historic Yerevan Brandy Company (Ararat).',
                'short' => 'Yerevan walking tour — Republic Square, Cascade, Tsitsernakaberd, Ararat brandy tasting.',
                'image' => 'https://images.unsplash.com/photo-1605649461784-bef214a93ab1?auto=format&fit=crop&w=1200&q=80',
                'duration' => '4 hours',
                'general_category' => 'cultural',
                'category' => 'city_walk',
                'excursion_type' => 'half_day',
                'group_size' => 12,
                'ticket_max' => 12,
                'language' => 'English',
                'starts_offset_days' => 5,
                'starts_hour' => '10:00',
                'ends_hour' => '14:00',
            ],
        ];

        foreach ($tours as $t) {
            if (DB::table('offers')->where('type', 'excursion')->where('title', $t['title'])->exists()) {
                continue;
            }

            $offerId = DB::table('offers')->insertGetId([
                'company_id' => $companyId,
                'type' => 'excursion',
                'title' => $t['title'],
                'price' => $t['price'],
                'currency' => 'USD',
                'status' => 'published',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $startsAt = $now->copy()->addDays($t['starts_offset_days']);
            $hm = explode(':', $t['starts_hour']);
            $startsAt->setTime((int) $hm[0], (int) $hm[1], 0);
            $endsAt = $now->copy()->addDays($t['starts_offset_days']);
            $hmE = explode(':', $t['ends_hour']);
            $endsAt->setTime((int) $hmE[0], (int) $hmE[1], 0);

            DB::table('excursions')->insert([
                'offer_id' => $offerId,
                'location' => 'Yerevan',
                'duration' => $t['duration'],
                'group_size' => $t['group_size'],
                'country' => 'Armenia',
                'city' => 'Yerevan',
                'general_category' => $t['general_category'],
                'category' => $t['category'],
                'excursion_type' => $t['excursion_type'],
                'tour_name' => $t['tour'],
                'overview' => $t['overview'],
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'language' => $t['language'],
                'ticket_max_count' => $t['ticket_max'],
                'status' => 'active',
                'is_available' => true,
                'is_bookable' => true,
                'visibility_rule' => 'show_all',
                'appears_in_web' => true,
                'appears_in_admin' => true,
                'appears_in_zulu_admin' => true,
                'short_description' => $t['short'],
                'main_image' => $t['image'],
                'location_id' => $yerevanLocationId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        $titles = [
            'Garni Temple + Geghard Monastery · Half-day · Yerevan',
            'Lake Sevan + Sevanavank · Full day · Yerevan',
            'Khor Virap + Areni Winery + Noravank · Full day · Yerevan',
            'Tatev Monastery + "Wings of Tatev" cable car · Full day',
            'Yerevan city walking tour + Cascade + brandy factory',
        ];

        $offerIds = DB::table('offers')
            ->where('type', 'excursion')
            ->whereIn('title', $titles)
            ->pluck('id')
            ->all();

        if (! empty($offerIds)) {
            DB::table('excursions')->whereIn('offer_id', $offerIds)->delete();
            DB::table('offers')->whereIn('id', $offerIds)->delete();
        }
    }
};
