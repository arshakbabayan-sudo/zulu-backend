<?php

namespace Database\Seeders;

use App\Models\Location;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Comprehensive country / region / city seeder.
 *
 * Built 2026-05-06 for the operator Hotel/Transfer/Excursion/Car forms
 * which need a populated Location tree to make the cascading
 * country → region → city pickers usable.
 *
 * Coverage:
 *   - Armenia: full (10 marzes + their main cities)
 *   - Russia: 10 federal subjects + 10 major cities
 *   - Egypt: 6 governorates + 10 cities
 *   - Arab world: 13 countries × 10 cities (UAE, KSA, Qatar, Kuwait,
 *     Bahrain, Oman, Jordan, Lebanon, Iraq, Syria, Morocco, Tunisia,
 *     Algeria)
 *   - Europe: 25 countries × ~10 cities each
 *
 * Uses firstOrNew so it's idempotent — re-running adds new entries
 * without duplicating existing ones.
 */
class WorldLocationSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedArmeniaFull();
        $this->seedRussia();
        $this->seedEgypt();
        $this->seedArabWorld();
        $this->seedEurope();
    }

    // ───────────────────────────────────────────────────────────────────
    // Armenia — full (10 marzes + Yerevan special status)
    // ───────────────────────────────────────────────────────────────────

    private function seedArmeniaFull(): void
    {
        $am = $this->country('Armenia', 'AM', '🇦🇲');

        $regions = [
            'Yerevan' => [
                'cities' => ['Yerevan'], // capital, treated as region for picker symmetry
            ],
            'Aragatsotn' => [
                'cities' => ['Ashtarak', 'Aparan', 'Talin', 'Byurakan', 'Oshakan'],
            ],
            'Ararat' => [
                'cities' => ['Artashat', 'Ararat', 'Masis', 'Vedi', 'Yeghegnavan'],
            ],
            'Armavir' => [
                'cities' => ['Armavir', 'Vagharshapat (Etchmiadzin)', 'Metsamor', 'Sardarapat', 'Aknashen'],
            ],
            'Gegharkunik' => [
                'cities' => ['Gavar', 'Sevan', 'Vardenis', 'Martuni', 'Chambarak'],
            ],
            'Kotayk' => [
                'cities' => ['Hrazdan', 'Abovyan', 'Tsaghkadzor', 'Charentsavan', 'Yeghvard', 'Nor Hachn'],
            ],
            'Lori' => [
                'cities' => ['Vanadzor', 'Alaverdi', 'Stepanavan', 'Spitak', 'Tashir', 'Akhtala'],
            ],
            'Shirak' => [
                'cities' => ['Gyumri', 'Artik', 'Maralik', 'Pemzashen', 'Akhuryan'],
            ],
            'Syunik' => [
                'cities' => ['Kapan', 'Goris', 'Sisian', 'Meghri', 'Agarak', 'Kajaran'],
            ],
            'Tavush' => [
                'cities' => ['Ijevan', 'Dilijan', 'Berd', 'Noyemberyan', 'Ayrum'],
            ],
            'Vayots Dzor' => [
                'cities' => ['Yeghegnadzor', 'Vayk', 'Jermuk', 'Areni', 'Malishka'],
            ],
        ];

        foreach ($regions as $regionName => $payload) {
            $region = $this->region($regionName, $am, 'AM');
            foreach ($payload['cities'] as $city) {
                $this->city($city, $region, 'AM');
            }
        }
    }

    // ───────────────────────────────────────────────────────────────────
    // Russia
    // ───────────────────────────────────────────────────────────────────

    private function seedRussia(): void
    {
        $ru = $this->country('Russia', 'RU', '🇷🇺');

        $subjects = [
            'Moscow Oblast' => ['Moscow', 'Khimki', 'Balashikha', 'Podolsk', 'Mytishchi'],
            'Saint Petersburg' => ['Saint Petersburg', 'Pushkin', 'Peterhof', 'Kronstadt'],
            'Krasnodar Krai' => ['Krasnodar', 'Sochi', 'Anapa', 'Gelendzhik', 'Novorossiysk'],
            'Sverdlovsk Oblast' => ['Yekaterinburg', 'Nizhny Tagil', 'Kamensk-Uralsky'],
            'Novosibirsk Oblast' => ['Novosibirsk', 'Berdsk', 'Iskitim'],
            'Republic of Tatarstan' => ['Kazan', 'Naberezhnye Chelny', 'Nizhnekamsk', 'Almetyevsk'],
            'Nizhny Novgorod Oblast' => ['Nizhny Novgorod', 'Dzerzhinsk', 'Arzamas'],
            'Chelyabinsk Oblast' => ['Chelyabinsk', 'Magnitogorsk', 'Zlatoust'],
            'Samara Oblast' => ['Samara', 'Tolyatti', 'Syzran'],
            'Rostov Oblast' => ['Rostov-on-Don', 'Taganrog', 'Volgodonsk'],
            'Republic of Crimea' => ['Simferopol', 'Yalta', 'Sevastopol', 'Alushta'],
            'Kaliningrad Oblast' => ['Kaliningrad', 'Svetlogorsk', 'Zelenogradsk'],
        ];
        foreach ($subjects as $regionName => $cities) {
            $region = $this->region($regionName, $ru, 'RU');
            foreach ($cities as $city) {
                $this->city($city, $region, 'RU');
            }
        }
    }

    // ───────────────────────────────────────────────────────────────────
    // Egypt
    // ───────────────────────────────────────────────────────────────────

    private function seedEgypt(): void
    {
        $eg = $this->country('Egypt', 'EG', '🇪🇬');
        $govs = [
            'Cairo Governorate' => ['Cairo', 'Heliopolis', 'New Cairo'],
            'Giza Governorate' => ['Giza', 'Sheikh Zayed City', '6th of October City'],
            'Alexandria Governorate' => ['Alexandria', 'Borg El Arab'],
            'Red Sea Governorate' => ['Hurghada', 'Sharm El Sheikh', 'Marsa Alam', 'Dahab', 'El Gouna', 'Safaga'],
            'South Sinai Governorate' => ['Sharm El Sheikh', 'Dahab', 'Nuweiba', 'Taba', 'Saint Catherine'],
            'Luxor Governorate' => ['Luxor'],
            'Aswan Governorate' => ['Aswan', 'Abu Simbel'],
        ];
        foreach ($govs as $regionName => $cities) {
            $region = $this->region($regionName, $eg, 'EG');
            foreach ($cities as $city) {
                $this->city($city, $region, 'EG');
            }
        }
    }

    // ───────────────────────────────────────────────────────────────────
    // Arab world (excluding Egypt, already done)
    // ───────────────────────────────────────────────────────────────────

    private function seedArabWorld(): void
    {
        $countries = [
            ['United Arab Emirates', 'AE', '🇦🇪', [
                'Dubai Emirate' => ['Dubai', 'Jebel Ali'],
                'Abu Dhabi Emirate' => ['Abu Dhabi', 'Al Ain', 'Liwa', 'Ruwais'],
                'Sharjah Emirate' => ['Sharjah', 'Khor Fakkan'],
                'Ajman Emirate' => ['Ajman'],
                'Ras Al Khaimah Emirate' => ['Ras Al Khaimah'],
                'Fujairah Emirate' => ['Fujairah'],
            ]],
            ['Saudi Arabia', 'SA', '🇸🇦', [
                'Riyadh Province' => ['Riyadh', 'Diriyah', 'Al Kharj'],
                'Makkah Province' => ['Mecca', 'Jeddah', 'Taif'],
                'Madinah Province' => ['Medina', 'Yanbu'],
                'Eastern Province' => ['Dammam', 'Khobar', 'Dhahran', 'Jubail'],
                'Asir Province' => ['Abha'],
            ]],
            ['Qatar', 'QA', '🇶🇦', [
                'Ad-Dawhah Municipality' => ['Doha', 'West Bay'],
                'Al Rayyan Municipality' => ['Al Rayyan', 'Education City'],
                'Al Wakrah Municipality' => ['Al Wakrah'],
                'Al Khor Municipality' => ['Al Khor'],
                'Lusail' => ['Lusail'],
            ]],
            ['Kuwait', 'KW', '🇰🇼', [
                'Capital Governorate' => ['Kuwait City'],
                'Hawalli Governorate' => ['Hawalli', 'Salmiya'],
                'Farwaniya Governorate' => ['Farwaniya'],
                'Ahmadi Governorate' => ['Al Ahmadi', 'Fahaheel', 'Mahboula'],
                'Jahra Governorate' => ['Jahra'],
            ]],
            ['Bahrain', 'BH', '🇧🇭', [
                'Capital Governorate' => ['Manama'],
                'Muharraq Governorate' => ['Muharraq'],
                'Northern Governorate' => ['Saar', 'Budaiya'],
                'Southern Governorate' => ['Riffa', 'Awali'],
            ]],
            ['Oman', 'OM', '🇴🇲', [
                'Muscat Governorate' => ['Muscat', 'Mutrah', 'Seeb'],
                'Dhofar Governorate' => ['Salalah'],
                'Musandam Governorate' => ['Khasab'],
                'Al Batinah North' => ['Sohar'],
                'Ad Dakhiliyah' => ['Nizwa'],
            ]],
            ['Jordan', 'JO', '🇯🇴', [
                'Amman Governorate' => ['Amman', 'Madaba'],
                'Aqaba Governorate' => ['Aqaba'],
                'Petra (Maan Governorate)' => ['Petra', 'Wadi Musa'],
                'Irbid Governorate' => ['Irbid'],
                'Dead Sea (Karak Governorate)' => ['Dead Sea Resorts'],
                'Zarqa Governorate' => ['Zarqa'],
            ]],
            ['Lebanon', 'LB', '🇱🇧', [
                'Beirut Governorate' => ['Beirut'],
                'Mount Lebanon' => ['Jounieh', 'Byblos (Jbeil)', 'Broummana'],
                'North Lebanon' => ['Tripoli', 'Batroun'],
                'South Lebanon' => ['Sidon', 'Tyre'],
                'Bekaa' => ['Zahle', 'Baalbek'],
            ]],
            ['Iraq', 'IQ', '🇮🇶', [
                'Baghdad Governorate' => ['Baghdad'],
                'Erbil Governorate' => ['Erbil'],
                'Sulaymaniyah Governorate' => ['Sulaymaniyah'],
                'Basra Governorate' => ['Basra'],
                'Najaf Governorate' => ['Najaf'],
                'Karbala Governorate' => ['Karbala'],
                'Mosul (Nineveh Governorate)' => ['Mosul'],
            ]],
            ['Syria', 'SY', '🇸🇾', [
                'Damascus Governorate' => ['Damascus'],
                'Aleppo Governorate' => ['Aleppo'],
                'Latakia Governorate' => ['Latakia'],
                'Tartus Governorate' => ['Tartus'],
                'Homs Governorate' => ['Homs', 'Palmyra'],
            ]],
            ['Morocco', 'MA', '🇲🇦', [
                'Casablanca-Settat' => ['Casablanca'],
                'Rabat-Sale-Kenitra' => ['Rabat', 'Sale'],
                'Marrakech-Safi' => ['Marrakech', 'Essaouira'],
                'Fes-Meknes' => ['Fes', 'Meknes'],
                'Tangier-Tetouan-Al Hoceima' => ['Tangier', 'Chefchaouen'],
                'Souss-Massa' => ['Agadir'],
            ]],
            ['Tunisia', 'TN', '🇹🇳', [
                'Tunis Governorate' => ['Tunis', 'Carthage'],
                'Sousse Governorate' => ['Sousse', 'Port El Kantaoui'],
                'Hammamet (Nabeul)' => ['Hammamet', 'Nabeul'],
                'Djerba (Medenine)' => ['Houmt Souk', 'Midoun'],
                'Monastir Governorate' => ['Monastir'],
                'Sfax Governorate' => ['Sfax'],
            ]],
            ['Algeria', 'DZ', '🇩🇿', [
                'Algiers Province' => ['Algiers'],
                'Oran Province' => ['Oran'],
                'Constantine Province' => ['Constantine'],
                'Annaba Province' => ['Annaba'],
                'Tlemcen Province' => ['Tlemcen'],
            ]],
        ];

        foreach ($countries as [$name, $code, $flag, $regions]) {
            $country = $this->country($name, $code, $flag);
            foreach ($regions as $regionName => $cities) {
                $region = $this->region($regionName, $country, $code);
                foreach ($cities as $city) {
                    $this->city($city, $region, $code);
                }
            }
        }
    }

    // ───────────────────────────────────────────────────────────────────
    // Europe (25 countries × ~10 cities)
    // ───────────────────────────────────────────────────────────────────

    private function seedEurope(): void
    {
        $countries = [
            ['United Kingdom', 'GB', '🇬🇧', [
                'England' => ['London', 'Manchester', 'Liverpool', 'Birmingham', 'Bristol', 'Brighton', 'Oxford', 'Cambridge'],
                'Scotland' => ['Edinburgh', 'Glasgow', 'Inverness'],
                'Wales' => ['Cardiff', 'Swansea'],
                'Northern Ireland' => ['Belfast'],
            ]],
            ['France', 'FR', '🇫🇷', [
                'Ile-de-France' => ['Paris', 'Versailles', 'Disneyland Paris'],
                "Provence-Alpes-Cote d'Azur" => ['Nice', 'Marseille', 'Cannes', 'Monaco-Ville', 'Saint-Tropez'],
                'Auvergne-Rhone-Alpes' => ['Lyon', 'Chamonix', 'Grenoble'],
                'Nouvelle-Aquitaine' => ['Bordeaux', 'Biarritz'],
                'Occitanie' => ['Toulouse'],
            ]],
            ['Germany', 'DE', '🇩🇪', [
                'Berlin' => ['Berlin'],
                'Bavaria' => ['Munich', 'Nuremberg', 'Garmisch-Partenkirchen'],
                'Hesse' => ['Frankfurt'],
                'Hamburg' => ['Hamburg'],
                'North Rhine-Westphalia' => ['Cologne', 'Dusseldorf', 'Dortmund'],
                'Baden-Wurttemberg' => ['Stuttgart', 'Heidelberg'],
                'Saxony' => ['Dresden', 'Leipzig'],
            ]],
            ['Italy', 'IT', '🇮🇹', [
                'Lazio' => ['Rome'],
                'Lombardy' => ['Milan'],
                'Veneto' => ['Venice', 'Verona'],
                'Tuscany' => ['Florence', 'Pisa', 'Siena'],
                'Campania' => ['Naples', 'Sorrento', 'Capri'],
                'Sicily' => ['Palermo', 'Catania', 'Taormina'],
                'Sardinia' => ['Cagliari', 'Olbia'],
            ]],
            ['Spain', 'ES', '🇪🇸', [
                'Madrid' => ['Madrid', 'Toledo'],
                'Catalonia' => ['Barcelona', 'Girona'],
                'Andalusia' => ['Seville', 'Granada', 'Malaga', 'Marbella'],
                'Valencia' => ['Valencia', 'Alicante', 'Benidorm'],
                'Balearic Islands' => ['Palma de Mallorca', 'Ibiza Town'],
                'Canary Islands' => ['Las Palmas', 'Tenerife (Santa Cruz)'],
            ]],
            ['Netherlands', 'NL', '🇳🇱', [
                'North Holland' => ['Amsterdam', 'Haarlem'],
                'South Holland' => ['Rotterdam', 'The Hague', 'Delft', 'Leiden'],
                'Utrecht' => ['Utrecht'],
                'North Brabant' => ['Eindhoven'],
                'Gelderland' => ['Arnhem'],
            ]],
            ['Belgium', 'BE', '🇧🇪', [
                'Brussels-Capital' => ['Brussels'],
                'Flanders' => ['Antwerp', 'Bruges', 'Ghent', 'Leuven'],
                'Wallonia' => ['Liege', 'Namur', 'Charleroi'],
            ]],
            ['Austria', 'AT', '🇦🇹', [
                'Vienna' => ['Vienna'],
                'Salzburg' => ['Salzburg', 'Zell am See'],
                'Tyrol' => ['Innsbruck', 'Kitzbuhel', 'Solden'],
                'Styria' => ['Graz'],
                'Vorarlberg' => ['Bregenz'],
            ]],
            ['Switzerland', 'CH', '🇨🇭', [
                'Zurich' => ['Zurich'],
                'Bern' => ['Bern', 'Interlaken'],
                'Geneva' => ['Geneva'],
                'Vaud' => ['Lausanne', 'Montreux'],
                'Valais' => ['Zermatt', 'Verbier'],
                'Graubunden' => ['St. Moritz', 'Davos'],
                'Lucerne' => ['Lucerne'],
            ]],
            ['Greece', 'GR', '🇬🇷', [
                'Attica' => ['Athens'],
                'Central Macedonia' => ['Thessaloniki', 'Halkidiki'],
                'South Aegean' => ['Mykonos', 'Santorini', 'Rhodes'],
                'Crete' => ['Heraklion', 'Chania'],
                'Ionian Islands' => ['Corfu', 'Zakynthos'],
            ]],
            ['Portugal', 'PT', '🇵🇹', [
                'Lisbon' => ['Lisbon', 'Cascais', 'Sintra'],
                'Porto' => ['Porto'],
                'Algarve' => ['Faro', 'Albufeira', 'Lagos'],
                'Madeira' => ['Funchal'],
                'Azores' => ['Ponta Delgada'],
            ]],
            ['Sweden', 'SE', '🇸🇪', [
                'Stockholm County' => ['Stockholm'],
                'Vastra Gotaland' => ['Gothenburg'],
                'Skane' => ['Malmo'],
                'Uppsala' => ['Uppsala'],
                'Norrbotten' => ['Kiruna', 'Lulea'],
            ]],
            ['Norway', 'NO', '🇳🇴', [
                'Oslo' => ['Oslo'],
                'Vestland' => ['Bergen', 'Flam'],
                'Trondelag' => ['Trondheim'],
                'Troms og Finnmark' => ['Tromso'],
                'Innlandet' => ['Lillehammer'],
            ]],
            ['Denmark', 'DK', '🇩🇰', [
                'Capital Region' => ['Copenhagen'],
                'Central Denmark' => ['Aarhus'],
                'Southern Denmark' => ['Odense', 'Esbjerg'],
                'North Denmark' => ['Aalborg'],
            ]],
            ['Finland', 'FI', '🇫🇮', [
                'Uusimaa' => ['Helsinki', 'Espoo'],
                'Pirkanmaa' => ['Tampere'],
                'Lapland' => ['Rovaniemi', 'Levi'],
                'Southwest Finland' => ['Turku'],
            ]],
            ['Poland', 'PL', '🇵🇱', [
                'Masovian Voivodeship' => ['Warsaw'],
                'Lesser Poland' => ['Krakow', 'Zakopane'],
                'Pomerania' => ['Gdansk', 'Sopot'],
                'Lower Silesia' => ['Wroclaw'],
                'Greater Poland' => ['Poznan'],
                'Lodz Voivodeship' => ['Lodz'],
            ]],
            ['Czech Republic', 'CZ', '🇨🇿', [
                'Prague' => ['Prague'],
                'South Moravia' => ['Brno'],
                'Karlovy Vary' => ['Karlovy Vary'],
                'South Bohemia' => ['Cesky Krumlov'],
                'Pilsen' => ['Pilsen'],
            ]],
            ['Hungary', 'HU', '🇭🇺', [
                'Budapest' => ['Budapest'],
                'Pest County' => ['Szentendre'],
                'Hajdu-Bihar' => ['Debrecen'],
                'Csongrad-Csanad' => ['Szeged'],
                'Veszprem County' => ['Lake Balaton (Balatonfured)'],
            ]],
            ['Romania', 'RO', '🇷🇴', [
                'Bucharest' => ['Bucharest'],
                'Brasov County' => ['Brasov', 'Bran'],
                'Cluj County' => ['Cluj-Napoca'],
                'Sibiu County' => ['Sibiu'],
                'Constanta County' => ['Constanta', 'Mamaia'],
            ]],
            ['Bulgaria', 'BG', '🇧🇬', [
                'Sofia' => ['Sofia'],
                'Plovdiv' => ['Plovdiv'],
                'Varna' => ['Varna', 'Golden Sands'],
                'Burgas' => ['Burgas', 'Sunny Beach', 'Nessebar'],
                'Bansko (Blagoevgrad)' => ['Bansko'],
            ]],
            ['Ireland', 'IE', '🇮🇪', [
                'Dublin' => ['Dublin'],
                'Galway' => ['Galway'],
                'Cork' => ['Cork'],
                'Kerry' => ['Killarney'],
                'Clare' => ['Doolin'],
            ]],
            ['Iceland', 'IS', '🇮🇸', [
                'Capital Region' => ['Reykjavik'],
                'Southern Region' => ['Vik', 'Selfoss'],
                'Northeastern Region' => ['Akureyri'],
            ]],
            ['Croatia', 'HR', '🇭🇷', [
                'City of Zagreb' => ['Zagreb'],
                'Split-Dalmatia' => ['Split', 'Hvar', 'Brac'],
                'Dubrovnik-Neretva' => ['Dubrovnik'],
                'Istria' => ['Pula', 'Rovinj'],
                'Zadar County' => ['Zadar'],
            ]],
            ['Serbia', 'RS', '🇷🇸', [
                'Belgrade' => ['Belgrade'],
                'South Backa' => ['Novi Sad'],
                'Nisava' => ['Nis'],
                'Sumadija' => ['Kragujevac'],
            ]],
            ['Ukraine', 'UA', '🇺🇦', [
                'Kyiv City' => ['Kyiv'],
                'Lviv Oblast' => ['Lviv'],
                'Odesa Oblast' => ['Odesa'],
                'Kharkiv Oblast' => ['Kharkiv'],
                'Dnipropetrovsk Oblast' => ['Dnipro'],
            ]],
        ];

        foreach ($countries as [$name, $code, $flag, $regions]) {
            $country = $this->country($name, $code, $flag);
            foreach ($regions as $regionName => $cities) {
                $region = $this->region($regionName, $country, $code);
                foreach ($cities as $city) {
                    $this->city($city, $region, $code);
                }
            }
        }
    }

    // ───────────────────────────────────────────────────────────────────
    // Helpers (idempotent)
    // ───────────────────────────────────────────────────────────────────

    private function country(string $name, string $iso2, ?string $flag = null): Location
    {
        $node = Location::query()->firstOrNew([
            'name' => $name,
            'type' => Location::TYPE_COUNTRY,
            'parent_id' => null,
        ]);
        $node->slug = Str::slug($name);
        $node->depth = 0;
        $node->code = $iso2;
        $node->country_code = $iso2;
        if ($flag !== null) $node->flag_emoji = $flag;
        $node->is_active = true;
        $node->save();
        if ($node->path === null) {
            $node->path = (string) $node->id;
            $node->save();
        }
        return $node->fresh();
    }

    private function region(string $name, Location $parent, string $countryCode): Location
    {
        $node = Location::query()->firstOrNew([
            'name' => $name,
            'type' => Location::TYPE_REGION,
            'parent_id' => $parent->id,
        ]);
        $node->slug = Str::slug($name);
        $node->depth = 1;
        $node->country_code = $countryCode;
        $node->is_active = true;
        $node->save();
        if ($node->path === null) {
            $node->path = trim((string) $parent->path, '/').'/'.$node->id;
            $node->save();
        }
        return $node->fresh();
    }

    private function city(string $name, Location $parent, string $countryCode): Location
    {
        $node = Location::query()->firstOrNew([
            'name' => $name,
            'type' => Location::TYPE_CITY,
            'parent_id' => $parent->id,
        ]);
        $node->slug = Str::slug($name);
        $node->depth = 2;
        $node->country_code = $countryCode;
        $node->is_active = true;
        $node->save();
        if ($node->path === null) {
            $node->path = trim((string) $parent->path, '/').'/'.$node->id;
            $node->save();
        }
        return $node->fresh();
    }
}
