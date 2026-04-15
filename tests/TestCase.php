<?php

namespace Tests;

use App\Models\Location;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * @return array{
     *   am_country:int,
     *   am_region:int,
     *   yerevan_city:int,
     *   gyumri_city:int,
     *   ge_country:int,
     *   ge_region:int,
     *   tbilisi_city:int
     * }
     */
    protected function locationIds(): array
    {
        $countryAm = Location::query()->firstOrCreate(
            ['slug' => 'am-country'],
            [
                'name' => 'Armenia',
                'type' => Location::TYPE_COUNTRY,
                'parent_id' => null,
                'depth' => 0,
                'path' => '',
            ]
        );
        if ($countryAm->path === '') {
            $countryAm->path = (string) $countryAm->id;
            $countryAm->save();
        }

        $regionAm = Location::query()->firstOrCreate(
            ['slug' => 'am-yerevan-region'],
            [
                'name' => 'Yerevan Region',
                'type' => Location::TYPE_REGION,
                'parent_id' => $countryAm->id,
                'depth' => 1,
                'path' => $countryAm->id.'/',
            ]
        );
        if ($regionAm->path === '' || $regionAm->path === $countryAm->id.'/') {
            $regionAm->path = $countryAm->id.'/'.$regionAm->id;
            $regionAm->save();
        }

        $yerevan = Location::query()->firstOrCreate(
            ['slug' => 'am-yerevan-city'],
            [
                'name' => 'Yerevan',
                'type' => Location::TYPE_CITY,
                'parent_id' => $regionAm->id,
                'depth' => 2,
                'path' => $countryAm->id.'/'.$regionAm->id.'/',
            ]
        );
        if ($yerevan->path === '' || str_ends_with($yerevan->path, '/')) {
            $yerevan->path = $countryAm->id.'/'.$regionAm->id.'/'.$yerevan->id;
            $yerevan->save();
        }

        $gyumri = Location::query()->firstOrCreate(
            ['slug' => 'am-gyumri-city'],
            [
                'name' => 'Gyumri',
                'type' => Location::TYPE_CITY,
                'parent_id' => $regionAm->id,
                'depth' => 2,
                'path' => $countryAm->id.'/'.$regionAm->id.'/',
            ]
        );
        if ($gyumri->path === '' || str_ends_with($gyumri->path, '/')) {
            $gyumri->path = $countryAm->id.'/'.$regionAm->id.'/'.$gyumri->id;
            $gyumri->save();
        }

        $countryGe = Location::query()->firstOrCreate(
            ['slug' => 'ge-country'],
            [
                'name' => 'Georgia',
                'type' => Location::TYPE_COUNTRY,
                'parent_id' => null,
                'depth' => 0,
                'path' => '',
            ]
        );
        if ($countryGe->path === '') {
            $countryGe->path = (string) $countryGe->id;
            $countryGe->save();
        }

        $regionGe = Location::query()->firstOrCreate(
            ['slug' => 'ge-tbilisi-region'],
            [
                'name' => 'Tbilisi Region',
                'type' => Location::TYPE_REGION,
                'parent_id' => $countryGe->id,
                'depth' => 1,
                'path' => $countryGe->id.'/',
            ]
        );
        if ($regionGe->path === '' || $regionGe->path === $countryGe->id.'/') {
            $regionGe->path = $countryGe->id.'/'.$regionGe->id;
            $regionGe->save();
        }

        $tbilisi = Location::query()->firstOrCreate(
            ['slug' => 'ge-tbilisi-city'],
            [
                'name' => 'Tbilisi',
                'type' => Location::TYPE_CITY,
                'parent_id' => $regionGe->id,
                'depth' => 2,
                'path' => $countryGe->id.'/'.$regionGe->id.'/',
            ]
        );
        if ($tbilisi->path === '' || str_ends_with($tbilisi->path, '/')) {
            $tbilisi->path = $countryGe->id.'/'.$regionGe->id.'/'.$tbilisi->id;
            $tbilisi->save();
        }

        return [
            'am_country' => (int) $countryAm->id,
            'am_region' => (int) $regionAm->id,
            'yerevan_city' => (int) $yerevan->id,
            'gyumri_city' => (int) $gyumri->id,
            'ge_country' => (int) $countryGe->id,
            'ge_region' => (int) $regionGe->id,
            'tbilisi_city' => (int) $tbilisi->id,
        ];
    }
}
