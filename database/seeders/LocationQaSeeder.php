<?php

namespace Database\Seeders;

use App\Models\Location;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class LocationQaSeeder extends Seeder
{
    public function run(): void
    {
        $armenia = $this->upsertNode('Armenia', Location::TYPE_COUNTRY, null);
        $kotayk = $this->upsertNode('Kotayk', Location::TYPE_REGION, $armenia);
        $this->upsertNode('Tsaghkadzor', Location::TYPE_CITY, $kotayk);

        $france = $this->upsertNode('France', Location::TYPE_COUNTRY, null);
        $idf = $this->upsertNode('IDF', Location::TYPE_REGION, $france);
        $this->upsertNode('Paris', Location::TYPE_CITY, $idf);
    }

    private function upsertNode(string $name, string $type, ?Location $parent): Location
    {
        $location = Location::query()->firstOrNew([
            'name' => $name,
            'type' => $type,
            'parent_id' => $parent?->id,
        ]);

        $location->slug = Str::slug($name);
        $location->depth = $parent ? ($parent->depth + 1) : 0;
        $location->save();

        $location->path = $parent
            ? trim((string) $parent->path, '/').'/'.$location->id
            : (string) $location->id;
        $location->save();

        return $location->fresh();
    }
}

