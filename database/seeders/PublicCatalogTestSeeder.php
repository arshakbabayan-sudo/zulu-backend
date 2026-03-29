<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Offer;
use App\Models\Package;
use Illuminate\Database\Seeder;

class PublicCatalogTestSeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::query()->updateOrCreate(
            ['name' => 'Zulu Public Catalog Seed Company'],
            [
                'type' => 'operator',
                'status' => 'active',
            ]
        );

        Offer::query()->updateOrCreate(
            ['title' => 'Seed Hotel Offer A'],
            [
                'company_id' => $company->id,
                'type' => 'hotel',
                'price' => 120.00,
                'currency' => 'USD',
                'status' => Offer::STATUS_PUBLISHED,
            ]
        );

        Offer::query()->updateOrCreate(
            ['title' => 'Seed Transfer Offer B'],
            [
                'company_id' => $company->id,
                'type' => 'transfer',
                'price' => 55.00,
                'currency' => 'USD',
                'status' => Offer::STATUS_PUBLISHED,
            ]
        );

        $packageOffer = Offer::query()->updateOrCreate(
            ['title' => 'Seed Package Offer C'],
            [
                'company_id' => $company->id,
                'type' => 'package',
                'price' => 499.00,
                'currency' => 'USD',
                'status' => Offer::STATUS_PUBLISHED,
            ]
        );

        Package::query()->updateOrCreate(
            ['offer_id' => $packageOffer->id],
            [
                'company_id' => $company->id,
                'package_type' => 'fixed',
                'package_title' => 'Seed Package Offer C',
                'package_subtitle' => 'Minimal seeded package for public catalog testing',
                'destination_country' => 'Armenia',
                'destination_city' => 'Yerevan',
                'duration_days' => 4,
                'min_nights' => 3,
                'adults_count' => 2,
                'children_count' => 0,
                'infants_count' => 0,
                'base_price' => 499.00,
                'display_price_mode' => 'total',
                'currency' => 'USD',
                'is_public' => true,
                'is_bookable' => true,
                'is_package_eligible' => true,
                'status' => 'active',
            ]
        );
    }
}
