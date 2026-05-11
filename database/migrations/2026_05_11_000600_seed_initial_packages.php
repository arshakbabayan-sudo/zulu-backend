<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Seed 3 demo travel packages that bundle existing Yerevan-based offers
     * (hotel + excursions + car). Mirrors the cars/excursions/transfers seed
     * shape but adds package_components rows that reference real offer IDs.
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

        // Defensive cleanup: previous failed runs may have orphan offers
        // without matching packages rows.
        DB::table('offers')
            ->where('type', 'package')
            ->whereNotIn('id', DB::table('packages')->select('offer_id'))
            ->delete();

        $now = now();

        // Resolve component offer IDs by title pattern (idempotent + survives
        // re-seeding the underlying modules). If a component is missing we
        // simply skip that line — the package itself still ships.
        $resolveOffer = function (string $type, string $titleLike): ?int {
            return DB::table('offers')
                ->where('type', $type)
                ->where('status', 'published')
                ->where('title', 'like', '%'.$titleLike.'%')
                ->value('id');
        };

        $packages = [
            [
                'title' => 'Yerevan Weekend Discovery · 3 days',
                'subtitle' => 'City walking tour + premium sedan rental, all from a downtown 4★ hotel.',
                'short' => 'Quick taste of Yerevan: a walking tour, brandy tasting, and your own sedan for 2 days.',
                'image' => 'https://images.unsplash.com/photo-1605649461784-bef214a93ab1?auto=format&fit=crop&w=1200&q=80',
                'price' => 340.00,
                'duration_days' => 3,
                'min_nights' => 2,
                'type' => 'city_break',
                'components' => [
                    ['type' => 'hotel', 'like' => 'Grand Erebuni', 'module' => 'hotel'],
                    ['type' => 'excursion', 'like' => 'Yerevan city walking tour', 'module' => 'excursion'],
                    ['type' => 'car', 'like' => 'Toyota Corolla', 'module' => 'car'],
                ],
            ],
            [
                'title' => 'Armenia Highlights · 5 days',
                'subtitle' => 'Garni + Sevan + a compact SUV — the country\'s essential half-week loop.',
                'short' => 'Five-day classic: Garni temple, Lake Sevan, and a 4WD SUV with full freedom.',
                'image' => 'https://images.unsplash.com/photo-1601128814389-7c0bdf3ec47f?auto=format&fit=crop&w=1200&q=80',
                'price' => 580.00,
                'duration_days' => 5,
                'min_nights' => 4,
                'type' => 'tour',
                'components' => [
                    ['type' => 'hotel', 'like' => 'Grand Erebuni', 'module' => 'hotel'],
                    ['type' => 'excursion', 'like' => 'Garni Temple', 'module' => 'excursion'],
                    ['type' => 'excursion', 'like' => 'Lake Sevan', 'module' => 'excursion'],
                    ['type' => 'car', 'like' => 'Hyundai Tucson', 'module' => 'car'],
                ],
            ],
            [
                'title' => 'Wine & Wonders · 7 days',
                'subtitle' => 'Khor Virap, Areni wine tasting, Tatev cable car, and a premium sedan.',
                'short' => 'A premium week: oldest winery in the world, Mount Ararat views, Tatev monastery on a basalt cliff.',
                'image' => 'https://images.unsplash.com/photo-1568667256549-094345857637?auto=format&fit=crop&w=1200&q=80',
                'price' => 820.00,
                'duration_days' => 7,
                'min_nights' => 6,
                'type' => 'tour',
                'components' => [
                    ['type' => 'hotel', 'like' => 'Grand Erebuni', 'module' => 'hotel'],
                    ['type' => 'excursion', 'like' => 'Khor Virap', 'module' => 'excursion'],
                    ['type' => 'excursion', 'like' => 'Tatev Monastery', 'module' => 'excursion'],
                    ['type' => 'car', 'like' => 'Mercedes-Benz E-Class', 'module' => 'car'],
                ],
            ],
        ];

        foreach ($packages as $p) {
            if (DB::table('offers')->where('type', 'package')->where('title', $p['title'])->exists()) {
                continue;
            }

            DB::transaction(function () use ($p, $companyId, $yerevanLocationId, $now, $resolveOffer) {
                $offerId = DB::table('offers')->insertGetId([
                    'company_id' => $companyId,
                    'type' => 'package',
                    'title' => $p['title'],
                    'price' => $p['price'],
                    'currency' => 'USD',
                    'status' => 'published',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $packageId = DB::table('packages')->insertGetId([
                    'offer_id' => $offerId,
                    'company_id' => $companyId,
                    'package_type' => $p['type'],
                    'package_title' => $p['title'],
                    'package_subtitle' => $p['subtitle'],
                    'destination_country' => 'Armenia',
                    'destination_city' => 'Yerevan',
                    'destination_location_id' => $yerevanLocationId,
                    'duration_days' => $p['duration_days'],
                    'min_nights' => $p['min_nights'],
                    'adults_count' => 2,
                    'children_count' => 0,
                    'infants_count' => 0,
                    'base_price' => $p['price'],
                    'display_price_mode' => 'per_person',
                    'currency' => 'USD',
                    'is_public' => true,
                    'is_bookable' => true,
                    'is_package_eligible' => true,
                    'visibility_rule' => 'show_all',
                    'is_featured' => true,
                    'component_count' => count($p['components']),
                    'status' => 'active',
                    'short_description' => $p['short'],
                    'main_image' => $p['image'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $sort = 0;
                foreach ($p['components'] as $c) {
                    $componentOfferId = $resolveOffer($c['type'], $c['like']);
                    if (! $componentOfferId) {
                        continue;
                    }
                    $sort++;
                    DB::table('package_components')->insert([
                        'package_id' => $packageId,
                        'offer_id' => $componentOfferId,
                        'service_type' => $c['type'],
                        'service_id' => $componentOfferId,
                        'module_type' => $c['module'],
                        'package_role' => $c['type'],
                        'is_required' => true,
                        'sort_order' => $sort,
                        'selection_mode' => 'fixed',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            });
        }
    }

    public function down(): void
    {
        $titles = [
            'Yerevan Weekend Discovery · 3 days',
            'Armenia Highlights · 5 days',
            'Wine & Wonders · 7 days',
        ];

        $offerIds = DB::table('offers')
            ->where('type', 'package')
            ->whereIn('title', $titles)
            ->pluck('id')
            ->all();

        if (! empty($offerIds)) {
            $packageIds = DB::table('packages')->whereIn('offer_id', $offerIds)->pluck('id')->all();
            if (! empty($packageIds)) {
                DB::table('package_components')->whereIn('package_id', $packageIds)->delete();
            }
            DB::table('packages')->whereIn('offer_id', $offerIds)->delete();
            DB::table('offers')->whereIn('id', $offerIds)->delete();
        }
    }
};
