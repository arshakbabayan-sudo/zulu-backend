<?php

namespace Database\Seeders;

use App\Models\Car;
use App\Models\Company;
use App\Models\Excursion;
use App\Models\Flight;
use App\Models\Hotel;
use App\Models\Location;
use App\Models\Offer;
use App\Models\Transfer;
use App\Models\Visa;
use Illuminate\Database\Seeder;

class ProductsQaSeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::query()->updateOrCreate(
            ['name' => 'Zulu QA Operator'],
            [
                'type' => 'operator',
                'status' => 'active',
            ]
        );

        $this->seedHotels($company);
        $this->seedFlights($company);
        $this->seedCars($company);
        $this->seedVisas($company);
        $this->seedExcursions($company);
        $this->seedTransfers($company);
    }

    private function seedHotels(Company $company): void
    {
        $rows = [
            ['hotel_name' => 'QA Hotel Yerevan', 'city' => ['Yerevan', 'Armenia', 'Yerevan']],
            ['hotel_name' => 'QA Hotel Paris', 'city' => ['Paris', 'France', 'Ile-de-France']],
            ['hotel_name' => 'QA Hotel Dubai', 'city' => ['Dubai', 'UAE', 'Dubai Emirate']],
        ];

        foreach ($rows as $row) {
            [$cityName, $countryName, $regionName] = $row['city'];
            $city = $this->city($cityName, $countryName, $regionName);

            $offer = $this->upsertOffer($company, 'hotel', $row['hotel_name'].' Offer');

            Hotel::query()->updateOrCreate(
                ['offer_id' => $offer->id],
                [
                    'company_id' => $company->id,
                    'hotel_name' => $row['hotel_name'],
                    'property_type' => 'hotel',
                    'hotel_type' => 'standard',
                    'location_id' => $city->id,
                    'meal_type' => 'room_only',
                    'status' => 'active',
                ]
            );
        }
    }

    private function seedFlights(Company $company): void
    {
        $rows = [
            [
                'title' => 'QA Flight YEREVAN->MOSCOW',
                'code' => 'QA-FLT-EVN-MOW',
                'from' => ['Yerevan', 'Armenia', 'Yerevan'],
                'to' => ['Moscow', 'Russia', 'Moscow Oblast'],
            ],
            [
                'title' => 'QA Flight YEREVAN->DUBAI',
                'code' => 'QA-FLT-EVN-DXB',
                'from' => ['Yerevan', 'Armenia', 'Yerevan'],
                'to' => ['Dubai', 'UAE', 'Dubai Emirate'],
            ],
            [
                'title' => 'QA Flight PARIS->FRANKFURT',
                'code' => 'QA-FLT-PAR-FRA',
                'from' => ['Paris', 'France', 'Ile-de-France'],
                'to' => ['Frankfurt', 'Germany', 'Hesse'],
            ],
        ];

        foreach ($rows as $row) {
            [$fromCityName, $fromCountryName, $fromRegionName] = $row['from'];
            [$toCityName, $toCountryName, $toRegionName] = $row['to'];
            $fromCity = $this->city($fromCityName, $fromCountryName, $fromRegionName);
            $toCity = $this->city($toCityName, $toCountryName, $toRegionName);
            $departureAt = now()->addDays(7)->setTime(9, 0);
            $arrivalAt = (clone $departureAt)->addHours(3);

            $offer = $this->upsertOffer($company, 'flight', $row['title'].' Offer');

            Flight::query()->updateOrCreate(
                ['offer_id' => $offer->id],
                [
                    'company_id' => $company->id,
                    'flight_code_internal' => $row['code'],
                    'service_type' => 'scheduled',
                    'departure_airport' => 'N/A',
                    'arrival_airport' => 'N/A',
                    'location_id' => $toCity->id,
                    'departure_at' => $departureAt,
                    'arrival_at' => $arrivalAt,
                    'duration_minutes' => 180,
                    'connection_type' => 'direct',
                    'stops_count' => 0,
                    'cabin_class' => 'economy',
                    'seat_capacity_total' => 180,
                    'seat_capacity_available' => 120,
                    'adult_age_from' => 18,
                    'child_age_from' => 2,
                    'child_age_to' => 11,
                    'infant_age_from' => 0,
                    'infant_age_to' => 1,
                    'adult_price' => 100,
                    'child_price' => 100,
                    'infant_price' => 0,
                    'hand_baggage_included' => true,
                    'checked_baggage_included' => false,
                    'reservation_allowed' => true,
                    'online_checkin_allowed' => true,
                    'airport_checkin_allowed' => true,
                    'cancellation_policy_type' => 'non_refundable',
                    'change_policy_type' => 'not_allowed',
                    'status' => 'active',
                ]
            );
        }
    }

    private function seedCars(Company $company): void
    {
        $rows = [
            ['title' => 'QA Car Yerevan', 'city' => ['Yerevan', 'Armenia', 'Yerevan']],
            ['title' => 'QA Car Tbilisi', 'city' => ['Tbilisi', 'Georgia', 'Tbilisi']],
        ];

        foreach ($rows as $row) {
            [$cityName, $countryName, $regionName] = $row['city'];
            $city = $this->city($cityName, $countryName, $regionName);

            $offer = $this->upsertOffer($company, 'car', $row['title'].' Offer');

            Car::query()->updateOrCreate(
                ['offer_id' => $offer->id],
                [
                    'location_id' => $city->id,
                    'vehicle_class' => 'economy',
                ]
            );
        }
    }

    private function seedVisas(Company $company): void
    {
        $rows = [
            ['title' => 'QA Visa UAE', 'country' => 'UAE'],
            ['title' => 'QA Visa Turkey', 'country' => 'Turkey'],
        ];

        foreach ($rows as $row) {
            $country = $this->country($row['country']);

            $offer = $this->upsertOffer($company, 'visa', $row['title'].' Offer');

            Visa::query()->updateOrCreate(
                ['offer_id' => $offer->id],
                [
                    'location_id' => $country->id,
                    'visa_type' => 'tourist',
                    'name' => $row['title'],
                    'price' => 100,
                ]
            );
        }
    }

    private function seedExcursions(Company $company): void
    {
        $rows = [
            ['title' => 'QA Excursion Tsaghkadzor', 'city' => ['Tsaghkadzor', 'Armenia', 'Kotayk']],
            ['title' => 'QA Excursion Paris', 'city' => ['Paris', 'France', 'Ile-de-France']],
        ];

        foreach ($rows as $row) {
            [$cityName, $countryName, $regionName] = $row['city'];
            $city = $this->city($cityName, $countryName, $regionName);

            $offer = $this->upsertOffer($company, 'excursion', $row['title'].' Offer');

            Excursion::query()->updateOrCreate(
                ['offer_id' => $offer->id],
                [
                    'duration' => '3h',
                    'group_size' => 10,
                    'location_id' => $city->id,
                    'status' => 'active',
                ]
            );
        }
    }

    private function seedTransfers(Company $company): void
    {
        $rows = [
            [
                'title' => 'QA Transfer YEREVAN->TBILISI',
                'origin' => ['Yerevan', 'Armenia', 'Yerevan'],
                'destination' => ['Tbilisi', 'Georgia', 'Tbilisi'],
            ],
            [
                'title' => 'QA Transfer PARIS->NICE',
                'origin' => ['Paris', 'France', 'Ile-de-France'],
                'destination' => ['Nice', 'France', "Provence-Alpes-Cote d'Azur"],
            ],
        ];

        foreach ($rows as $row) {
            [$originCityName, $originCountryName, $originRegionName] = $row['origin'];
            [$destinationCityName, $destinationCountryName, $destinationRegionName] = $row['destination'];
            $originCity = $this->city($originCityName, $originCountryName, $originRegionName);
            $destinationCity = $this->city($destinationCityName, $destinationCountryName, $destinationRegionName);

            $offer = $this->upsertOffer($company, 'transfer', $row['title'].' Offer');

            Transfer::query()->updateOrCreate(
                ['offer_id' => $offer->id],
                [
                    'company_id' => $company->id,
                    'transfer_title' => $row['title'],
                    'transfer_type' => 'private_transfer',
                    'origin_location_id' => $originCity->id,
                    'pickup_point_type' => 'address',
                    'pickup_point_name' => $originCity->name.' center',
                    'destination_location_id' => $destinationCity->id,
                    'dropoff_point_type' => 'address',
                    'dropoff_point_name' => $destinationCity->name.' center',
                    'service_date' => now()->addDays(3)->toDateString(),
                    'pickup_time' => '10:00:00',
                    'estimated_duration_minutes' => 120,
                    'vehicle_category' => 'sedan',
                    'passenger_capacity' => 3,
                    'minimum_passengers' => 1,
                    'maximum_passengers' => 3,
                    'pricing_mode' => 'per_vehicle',
                    'base_price' => 100,
                    'cancellation_policy_type' => 'non_refundable',
                    'status' => 'active',
                ]
            );
        }
    }

    private function upsertOffer(Company $company, string $type, string $title): Offer
    {
        return Offer::query()->updateOrCreate(
            ['title' => $title],
            [
                'company_id' => $company->id,
                'type' => $type,
                'price' => 100,
                'currency' => 'USD',
                'status' => Offer::STATUS_PUBLISHED,
            ]
        );
    }

    private function country(string $countryName): Location
    {
        return Location::query()
            ->where('type', Location::TYPE_COUNTRY)
            ->where('name', $countryName)
            ->firstOrFail();
    }

    private function region(string $regionName, string $countryName): Location
    {
        $country = $this->country($countryName);

        return Location::query()
            ->where('type', Location::TYPE_REGION)
            ->where('name', $regionName)
            ->where('parent_id', $country->id)
            ->firstOrFail();
    }

    private function city(string $cityName, string $countryName, string $regionName): Location
    {
        $region = $this->region($regionName, $countryName);

        return Location::query()
            ->where('type', Location::TYPE_CITY)
            ->where('name', $cityName)
            ->where('parent_id', $region->id)
            ->firstOrFail();
    }
}
