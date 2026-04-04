<?php

namespace App\Http\Resources\Api\Concerns;

use App\Http\Resources\Api\CatalogOfferDetailResource;
use App\Http\Resources\Api\OfferResource;

/**
 * Contract: summary-only module snapshots embedded in offer payloads (operator {@see OfferResource}
 * and catalog {@see CatalogOfferDetailResource}).
 *
 * Rules (do not regress):
 * - Each *ModuleSummary() must return a single-level array whose values are scalars (or null) only — no nested arrays,
 *   no child collections, no relation graphs.
 * - No module pricing breakdowns, line items, or “full module” shapes here; those live on module endpoints only.
 * - Do not substitute a module detail resource or deep serialization inside offer resources; keep summaries aligned
 *   across both offer resource classes via this trait only.
 */
trait SummarizesOfferModules
{
    /**
     * @return array<string, mixed>
     */
    private function flightModuleSummary(): array
    {
        $f = $this->flight;

        return [
            'id' => $f->id,
            'offer_id' => $f->offer_id,
            'flight_code_internal' => $f->flight_code_internal,
            'departure_country' => $f->departure_country,
            'departure_city' => $f->departure_city,
            'arrival_country' => $f->arrival_country,
            'arrival_city' => $f->arrival_city,
            'departure_airport_code' => $f->departure_airport_code,
            'arrival_airport_code' => $f->arrival_airport_code,
            'departure_at' => $f->departure_at?->toIso8601String(),
            'arrival_at' => $f->arrival_at?->toIso8601String(),
            'duration_minutes' => $f->duration_minutes,
            'connection_type' => $f->connection_type,
            'stops_count' => $f->stops_count,
            'cabin_class' => $f->cabin_class,
            'status' => $f->status,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function hotelModuleSummary(): array
    {
        $h = $this->hotel;

        return [
            'id' => $h->id,
            'offer_id' => $h->offer_id,
            'hotel_name' => $h->hotel_name,
            'country' => $h->country,
            'city' => $h->city,
            'district_or_area' => $h->district_or_area,
            'star_rating' => $h->star_rating,
            'meal_type' => $h->meal_type,
            'main_image' => $h->main_image,
            'availability_status' => $h->availability_status,
            'status' => $h->status,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function transferModuleSummary(): array
    {
        $t = $this->transfer;

        return [
            'id' => $t->id,
            'offer_id' => $t->offer_id,
            'transfer_title' => $t->transfer_title,
            'pickup_city' => $t->pickup_city,
            'pickup_point_name' => $t->pickup_point_name,
            'dropoff_city' => $t->dropoff_city,
            'dropoff_point_name' => $t->dropoff_point_name,
            'route_label' => $t->route_label,
            'service_date' => $t->service_date?->format('Y-m-d'),
            'pickup_time' => $this->formatTransferPickupTime($t->pickup_time),
            'estimated_duration_minutes' => $t->estimated_duration_minutes,
            'vehicle_category' => $t->vehicle_category,
            'private_or_shared' => $t->private_or_shared,
            'availability_status' => $t->availability_status,
            'status' => $t->status,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function carModuleSummary(): array
    {
        $c = $this->car;

        return [
            'id' => $c->id,
            'offer_id' => $c->offer_id,
            'pickup_location' => $c->pickup_location,
            'dropoff_location' => $c->dropoff_location,
            'vehicle_class' => $c->vehicle_class,
            'brand' => $c->brand,
            'model' => $c->model,
            'availability_status' => $c->availability_status,
            'status' => $c->status,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function excursionModuleSummary(): array
    {
        $e = $this->excursion;

        return [
            'id' => $e->id,
            'offer_id' => $e->offer_id,
            'tour_name' => $e->tour_name,
            'location' => $e->location,
            'country' => $e->country,
            'city' => $e->city,
            'duration' => $e->duration,
            'group_size' => $e->group_size,
            'status' => $e->status,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function packageModuleSummary(): array
    {
        $p = $this->package;

        return [
            'id' => $p->id,
            'offer_id' => $p->offer_id,
            'package_id' => $p->id,
            'destination' => $p->destination,
            'duration_days' => $p->duration_days,
            'package_type' => $p->package_type,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function visaModuleSummary(): array
    {
        $v = $this->visa;

        return [
            'id' => $v->id,
            'offer_id' => $v->offer_id,
            'country' => $v->country,
            'visa_type' => $v->visa_type,
            'processing_days' => $v->processing_days,
        ];
    }

    private function formatTransferPickupTime(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('H:i:s');
        }

        return (string) $value;
    }
}
