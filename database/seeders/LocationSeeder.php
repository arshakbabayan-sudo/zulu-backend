<?php

namespace Database\Seeders;

use App\Models\Location;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class LocationSeeder extends Seeder
{
    public function run(): void
    {
        $armenia = $this->upsertNode('Armenia', Location::TYPE_COUNTRY, null);
        $yerevanRegion = $this->upsertNode('Yerevan', Location::TYPE_REGION, $armenia);
        $this->upsertNode('Yerevan', Location::TYPE_CITY, $yerevanRegion);
        $kotayk = $this->upsertNode('Kotayk', Location::TYPE_REGION, $armenia);
        $this->upsertNode('Tsaghkadzor', Location::TYPE_CITY, $kotayk);
        $this->upsertNode('Abovyan', Location::TYPE_CITY, $kotayk);
        $lori = $this->upsertNode('Lori', Location::TYPE_REGION, $armenia);
        $this->upsertNode('Vanadzor', Location::TYPE_CITY, $lori);
        $syunik = $this->upsertNode('Syunik', Location::TYPE_REGION, $armenia);
        $this->upsertNode('Goris', Location::TYPE_CITY, $syunik);

        $russia = $this->upsertNode('Russia', Location::TYPE_COUNTRY, null);
        $moscowOblast = $this->upsertNode('Moscow Oblast', Location::TYPE_REGION, $russia);
        $this->upsertNode('Moscow', Location::TYPE_CITY, $moscowOblast);
        $saintPetersburgRegion = $this->upsertNode('Saint Petersburg', Location::TYPE_REGION, $russia);
        $this->upsertNode('Saint Petersburg', Location::TYPE_CITY, $saintPetersburgRegion);
        $krasnodarKrai = $this->upsertNode('Krasnodar Krai', Location::TYPE_REGION, $russia);
        $this->upsertNode('Sochi', Location::TYPE_CITY, $krasnodarKrai);

        $uae = $this->upsertNode('UAE', Location::TYPE_COUNTRY, null);
        $dubaiEmirate = $this->upsertNode('Dubai Emirate', Location::TYPE_REGION, $uae);
        $this->upsertNode('Dubai', Location::TYPE_CITY, $dubaiEmirate);
        $abuDhabiEmirate = $this->upsertNode('Abu Dhabi Emirate', Location::TYPE_REGION, $uae);
        $this->upsertNode('Abu Dhabi', Location::TYPE_CITY, $abuDhabiEmirate);

        $qatar = $this->upsertNode('Qatar', Location::TYPE_COUNTRY, null);
        $dohaMunicipality = $this->upsertNode('Doha Municipality', Location::TYPE_REGION, $qatar);
        $this->upsertNode('Doha', Location::TYPE_CITY, $dohaMunicipality);

        $france = $this->upsertNode('France', Location::TYPE_COUNTRY, null);
        $ileDeFrance = $this->upsertNode('Ile-de-France', Location::TYPE_REGION, $france);
        $this->upsertNode('Paris', Location::TYPE_CITY, $ileDeFrance);
        $paca = $this->upsertNode("Provence-Alpes-Cote d'Azur", Location::TYPE_REGION, $france);
        $this->upsertNode('Nice', Location::TYPE_CITY, $paca);
        $this->upsertNode('Marseille', Location::TYPE_CITY, $paca);

        $poland = $this->upsertNode('Poland', Location::TYPE_COUNTRY, null);
        $masovianVoivodeship = $this->upsertNode('Masovian Voivodeship', Location::TYPE_REGION, $poland);
        $this->upsertNode('Warsaw', Location::TYPE_CITY, $masovianVoivodeship);
        $lesserPoland = $this->upsertNode('Lesser Poland', Location::TYPE_REGION, $poland);
        $this->upsertNode('Krakow', Location::TYPE_CITY, $lesserPoland);

        $turkey = $this->upsertNode('Turkey', Location::TYPE_COUNTRY, null);
        $istanbulProvince = $this->upsertNode('Istanbul Province', Location::TYPE_REGION, $turkey);
        $this->upsertNode('Istanbul', Location::TYPE_CITY, $istanbulProvince);
        $antalyaProvince = $this->upsertNode('Antalya Province', Location::TYPE_REGION, $turkey);
        $this->upsertNode('Antalya', Location::TYPE_CITY, $antalyaProvince);

        $austria = $this->upsertNode('Austria', Location::TYPE_COUNTRY, null);
        $viennaRegion = $this->upsertNode('Vienna', Location::TYPE_REGION, $austria);
        $this->upsertNode('Vienna', Location::TYPE_CITY, $viennaRegion);
        $tyrol = $this->upsertNode('Tyrol', Location::TYPE_REGION, $austria);
        $this->upsertNode('Innsbruck', Location::TYPE_CITY, $tyrol);

        $germany = $this->upsertNode('Germany', Location::TYPE_COUNTRY, null);
        $hesse = $this->upsertNode('Hesse', Location::TYPE_REGION, $germany);
        $this->upsertNode('Frankfurt', Location::TYPE_CITY, $hesse);
        $bavaria = $this->upsertNode('Bavaria', Location::TYPE_REGION, $germany);
        $this->upsertNode('Munich', Location::TYPE_CITY, $bavaria);
        $berlinRegion = $this->upsertNode('Berlin', Location::TYPE_REGION, $germany);
        $this->upsertNode('Berlin', Location::TYPE_CITY, $berlinRegion);

        $georgia = $this->upsertNode('Georgia', Location::TYPE_COUNTRY, null);
        $tbilisiRegion = $this->upsertNode('Tbilisi', Location::TYPE_REGION, $georgia);
        $this->upsertNode('Tbilisi', Location::TYPE_CITY, $tbilisiRegion);
        $adjara = $this->upsertNode('Adjara', Location::TYPE_REGION, $georgia);
        $this->upsertNode('Batumi', Location::TYPE_CITY, $adjara);

        $unitedKingdom = $this->upsertNode('United Kingdom', Location::TYPE_COUNTRY, null);
        $england = $this->upsertNode('England', Location::TYPE_REGION, $unitedKingdom);
        $this->upsertNode('London', Location::TYPE_CITY, $england);
        $this->upsertNode('Manchester', Location::TYPE_CITY, $england);

        $italy = $this->upsertNode('Italy', Location::TYPE_COUNTRY, null);
        $lazio = $this->upsertNode('Lazio', Location::TYPE_REGION, $italy);
        $this->upsertNode('Rome', Location::TYPE_CITY, $lazio);
        $lombardy = $this->upsertNode('Lombardy', Location::TYPE_REGION, $italy);
        $this->upsertNode('Milan', Location::TYPE_CITY, $lombardy);

        $spain = $this->upsertNode('Spain', Location::TYPE_COUNTRY, null);
        $madridRegion = $this->upsertNode('Madrid', Location::TYPE_REGION, $spain);
        $this->upsertNode('Madrid', Location::TYPE_CITY, $madridRegion);
        $catalonia = $this->upsertNode('Catalonia', Location::TYPE_REGION, $spain);
        $this->upsertNode('Barcelona', Location::TYPE_CITY, $catalonia);

        $unitedStates = $this->upsertNode('United States', Location::TYPE_COUNTRY, null);
        $newYork = $this->upsertNode('New York', Location::TYPE_REGION, $unitedStates);
        $this->upsertNode('New York', Location::TYPE_CITY, $newYork);
        $california = $this->upsertNode('California', Location::TYPE_REGION, $unitedStates);
        $this->upsertNode('Los Angeles', Location::TYPE_CITY, $california);
        $this->upsertNode('San Francisco', Location::TYPE_CITY, $california);

        $egypt = $this->upsertNode('Egypt', Location::TYPE_COUNTRY, null);
        $cairoGovernorate = $this->upsertNode('Cairo Governorate', Location::TYPE_REGION, $egypt);
        $this->upsertNode('Cairo', Location::TYPE_CITY, $cairoGovernorate);
        $redSeaGovernorate = $this->upsertNode('Red Sea Governorate', Location::TYPE_REGION, $egypt);
        $this->upsertNode('Hurghada', Location::TYPE_CITY, $redSeaGovernorate);
        $this->upsertNode('Sharm El Sheikh', Location::TYPE_CITY, $redSeaGovernorate);
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
