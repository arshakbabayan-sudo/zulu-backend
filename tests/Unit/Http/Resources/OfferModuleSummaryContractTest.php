<?php

namespace Tests\Unit\Http\Resources;

use App\Http\Resources\Api\CatalogOfferDetailResource;
use App\Http\Resources\Api\OfferResource;
use App\Models\Company;
use App\Models\Flight;
use App\Models\Offer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\MissingValue;
use Tests\TestCase;

class OfferModuleSummaryContractTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private const MODULE_KEYS = ['flight', 'hotel', 'transfer', 'car', 'excursion', 'package', 'visa'];

    public function test_module_summaries_are_flat_and_aligned_between_operator_and_catalog_resources(): void
    {
        $company = Company::query()->create([
            'name' => 'Contract Co',
            'type' => 'agency',
            'status' => 'active',
        ]);

        $offer = Offer::query()->create([
            'company_id' => $company->id,
            'type' => 'flight',
            'title' => 'Contract flight',
            'price' => 50,
            'currency' => 'USD',
            'status' => 'draft',
        ]);

        Flight::query()->create([
            'offer_id' => $offer->id,
            'company_id' => $company->id,
            'flight_code_internal' => 'CTR-F1',
            'service_type' => 'scheduled',
            'departure_country' => 'AM',
            'departure_city' => 'Yerevan',
            'departure_airport' => 'EVN',
            'arrival_country' => 'EG',
            'arrival_city' => 'Sharm',
            'arrival_airport' => 'SSH',
            'departure_at' => '2026-09-01 08:00:00',
            'arrival_at' => '2026-09-01 12:00:00',
            'duration_minutes' => 240,
            'connection_type' => 'direct',
            'stops_count' => 0,
            'cabin_class' => 'economy',
            'seat_capacity_total' => 150,
            'seat_capacity_available' => 20,
            'adult_age_from' => 18,
            'child_age_from' => 2,
            'child_age_to' => 11,
            'infant_age_from' => 0,
            'infant_age_to' => 1,
            'adult_price' => 50,
            'child_price' => 0,
            'infant_price' => 0,
            'hand_baggage_included' => false,
            'checked_baggage_included' => false,
            'reservation_allowed' => true,
            'online_checkin_allowed' => true,
            'airport_checkin_allowed' => true,
            'cancellation_policy_type' => 'non_refundable',
            'change_policy_type' => 'not_allowed',
            'seat_map_available' => false,
            'extra_baggage_allowed' => false,
            'is_package_eligible' => false,
            'status' => 'draft',
        ]);

        $offer->load('flight');

        $request = Request::create('/');
        $operator = (new OfferResource($offer))->toArray($request);
        $catalog = (new CatalogOfferDetailResource($offer))->toArray($request);

        $this->assertModuleSummariesContainNoNestedArrays($operator);
        $this->assertModuleSummariesContainNoNestedArrays($catalog);

        foreach (self::MODULE_KEYS as $key) {
            $o = $operator[$key] ?? null;
            $c = $catalog[$key] ?? null;
            if ($o instanceof MissingValue) {
                $o = null;
            }
            if ($c instanceof MissingValue) {
                $c = null;
            }
            $this->assertSame(
                $o,
                $c,
                "Module key \"{$key}\" must match between OfferResource and CatalogOfferDetailResource"
            );
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertModuleSummariesContainNoNestedArrays(array $payload): void
    {
        foreach (self::MODULE_KEYS as $key) {
            if (! array_key_exists($key, $payload)) {
                continue;
            }
            $block = $payload[$key];
            if ($block instanceof MissingValue) {
                continue;
            }
            if (! is_array($block)) {
                $this->assertNull($block, "Module key \"{$key}\" must be null or an array of scalars");

                continue;
            }
            foreach ($block as $field => $value) {
                $this->assertTrue(
                    is_scalar($value) || $value === null,
                    "Module \"{$key}\" field \"{$field}\" must be scalar or null (no nested child data)"
                );
            }
        }
    }
}
