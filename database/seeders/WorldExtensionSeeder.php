<?php

namespace Database\Seeders;

use App\Models\Location;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Adds the rest of the world's countries on top of WorldLocationSeeder.
 *
 * Coverage strategy:
 *   - Countries already seeded by WorldLocationSeeder are skipped
 *     (firstOrNew is idempotent, so running this is always safe).
 *   - Each country gets 5-8 popular cities directly under it (no
 *     intermediate region tier — operators picking a city in this set
 *     resolve to (country -> city) directly, which keeps the data
 *     model consistent with what's already there).
 *   - Cities chosen to skew toward common tour-operator destinations
 *     (capitals, business hubs, tourist hotspots, ports, ski resorts).
 */
class WorldExtensionSeeder extends Seeder
{
    public function run(): void
    {
        // [name, ISO-2, flag, [cities]]
        $countries = [
            // ─── Asia (extended) ───────────────────────────────────────────
            ['Turkey', 'TR', '🇹🇷', ['Istanbul', 'Ankara', 'Antalya', 'Izmir', 'Bursa', 'Bodrum', 'Cappadocia', 'Trabzon']],
            ['Cyprus', 'CY', '🇨🇾', ['Nicosia', 'Limassol', 'Larnaca', 'Paphos', 'Ayia Napa', 'Protaras']],
            ['Israel', 'IL', '🇮🇱', ['Jerusalem', 'Tel Aviv', 'Haifa', 'Eilat', 'Tiberias', 'Nazareth']],
            ['Palestine', 'PS', '🇵🇸', ['Ramallah', 'Bethlehem', 'Hebron', 'Nablus', 'Jericho', 'Gaza']],
            ['Yemen', 'YE', '🇾🇪', ['Sanaa', 'Aden', 'Taiz', 'Hodeidah', 'Mukalla', 'Ibb']],
            ['Iran', 'IR', '🇮🇷', ['Tehran', 'Isfahan', 'Mashhad', 'Shiraz', 'Tabriz', 'Yazd', 'Kish Island']],
            ['Afghanistan', 'AF', '🇦🇫', ['Kabul', 'Herat', 'Kandahar', 'Mazar-i-Sharif', 'Bamyan']],
            ['Pakistan', 'PK', '🇵🇰', ['Islamabad', 'Karachi', 'Lahore', 'Peshawar', 'Multan', 'Faisalabad', 'Quetta']],
            ['India', 'IN', '🇮🇳', ['New Delhi', 'Mumbai', 'Bengaluru', 'Chennai', 'Kolkata', 'Hyderabad', 'Goa', 'Jaipur', 'Agra', 'Kerala']],
            ['Bangladesh', 'BD', '🇧🇩', ['Dhaka', 'Chittagong', 'Sylhet', 'Khulna', 'Cox\'s Bazar']],
            ['Sri Lanka', 'LK', '🇱🇰', ['Colombo', 'Kandy', 'Galle', 'Negombo', 'Sigiriya', 'Nuwara Eliya', 'Bentota']],
            ['Maldives', 'MV', '🇲🇻', ['Malé', 'Hulhumalé', 'Maafushi', 'Addu City', 'Fuvahmulah', 'Thulusdhoo']],
            ['Nepal', 'NP', '🇳🇵', ['Kathmandu', 'Pokhara', 'Lalitpur', 'Bhaktapur', 'Chitwan', 'Lumbini']],
            ['Bhutan', 'BT', '🇧🇹', ['Thimphu', 'Paro', 'Punakha', 'Phuentsholing', 'Bumthang']],
            ['China', 'CN', '🇨🇳', ['Beijing', 'Shanghai', 'Guangzhou', 'Shenzhen', 'Xi\'an', 'Chengdu', 'Hangzhou', 'Sanya', 'Hong Kong', 'Macau']],
            ['Mongolia', 'MN', '🇲🇳', ['Ulaanbaatar', 'Erdenet', 'Darkhan', 'Khovd', 'Karakorum']],
            ['South Korea', 'KR', '🇰🇷', ['Seoul', 'Busan', 'Incheon', 'Jeju', 'Daegu', 'Gyeongju', 'Gangneung']],
            ['Japan', 'JP', '🇯🇵', ['Tokyo', 'Osaka', 'Kyoto', 'Hiroshima', 'Sapporo', 'Nagoya', 'Fukuoka', 'Okinawa']],
            ['Taiwan', 'TW', '🇹🇼', ['Taipei', 'Kaohsiung', 'Taichung', 'Tainan', 'Hualien', 'Taoyuan']],
            ['Vietnam', 'VN', '🇻🇳', ['Hanoi', 'Ho Chi Minh City', 'Da Nang', 'Hoi An', 'Nha Trang', 'Phu Quoc', 'Hue', 'Sapa']],
            ['Laos', 'LA', '🇱🇦', ['Vientiane', 'Luang Prabang', 'Pakse', 'Vang Vieng', 'Savannakhet']],
            ['Cambodia', 'KH', '🇰🇭', ['Phnom Penh', 'Siem Reap', 'Sihanoukville', 'Battambang', 'Kampot', 'Kep']],
            ['Thailand', 'TH', '🇹🇭', ['Bangkok', 'Phuket', 'Chiang Mai', 'Pattaya', 'Krabi', 'Koh Samui', 'Hua Hin', 'Ayutthaya']],
            ['Myanmar', 'MM', '🇲🇲', ['Yangon', 'Mandalay', 'Naypyidaw', 'Bagan', 'Inle Lake']],
            ['Malaysia', 'MY', '🇲🇾', ['Kuala Lumpur', 'Penang', 'Langkawi', 'Kota Kinabalu', 'Malacca', 'Johor Bahru', 'Kuching']],
            ['Singapore', 'SG', '🇸🇬', ['Singapore']],
            ['Indonesia', 'ID', '🇮🇩', ['Jakarta', 'Bali', 'Yogyakarta', 'Surabaya', 'Bandung', 'Lombok', 'Medan', 'Makassar']],
            ['Philippines', 'PH', '🇵🇭', ['Manila', 'Cebu', 'Boracay', 'Palawan', 'Davao', 'Bohol', 'Baguio']],
            ['Brunei', 'BN', '🇧🇳', ['Bandar Seri Begawan', 'Kuala Belait', 'Tutong', 'Seria']],
            ['Timor-Leste', 'TL', '🇹🇱', ['Dili', 'Baucau', 'Maliana', 'Same']],
            ['Kazakhstan', 'KZ', '🇰🇿', ['Astana', 'Almaty', 'Shymkent', 'Karaganda', 'Aktobe', 'Atyrau']],
            ['Uzbekistan', 'UZ', '🇺🇿', ['Tashkent', 'Samarkand', 'Bukhara', 'Khiva', 'Fergana', 'Nukus']],
            ['Turkmenistan', 'TM', '🇹🇲', ['Ashgabat', 'Türkmenbaşy', 'Mary', 'Daşoguz']],
            ['Tajikistan', 'TJ', '🇹🇯', ['Dushanbe', 'Khujand', 'Bokhtar', 'Khorugh']],
            ['Kyrgyzstan', 'KG', '🇰🇬', ['Bishkek', 'Osh', 'Karakol', 'Cholpon-Ata', 'Naryn']],
            ['Georgia', 'GE', '🇬🇪', ['Tbilisi', 'Batumi', 'Kutaisi', 'Borjomi', 'Bakuriani', 'Mestia', 'Stepantsminda', 'Sighnaghi']],
            ['Azerbaijan', 'AZ', '🇦🇿', ['Baku', 'Ganja', 'Sumqayit', 'Sheki', 'Quba', 'Lankaran', 'Gabala']],
            ['North Korea', 'KP', '🇰🇵', ['Pyongyang', 'Kaesong', 'Wonsan', 'Hamhung', 'Chongjin']],

            // ─── Europe (extended) ─────────────────────────────────────────
            ['Albania', 'AL', '🇦🇱', ['Tirana', 'Durrës', 'Vlorë', 'Sarandë', 'Berat', 'Shkodër', 'Gjirokastër']],
            ['Andorra', 'AD', '🇦🇩', ['Andorra la Vella', 'Escaldes-Engordany', 'Encamp', 'La Massana', 'Soldeu']],
            ['Belarus', 'BY', '🇧🇾', ['Minsk', 'Brest', 'Grodno', 'Vitebsk', 'Mogilev', 'Gomel']],
            ['Bosnia and Herzegovina', 'BA', '🇧🇦', ['Sarajevo', 'Mostar', 'Banja Luka', 'Tuzla', 'Zenica', 'Međugorje']],
            ['Estonia', 'EE', '🇪🇪', ['Tallinn', 'Tartu', 'Narva', 'Pärnu', 'Saaremaa']],
            ['Kosovo', 'XK', '🇽🇰', ['Pristina', 'Prizren', 'Peja', 'Mitrovica', 'Gjilan']],
            ['Latvia', 'LV', '🇱🇻', ['Riga', 'Daugavpils', 'Liepāja', 'Jūrmala', 'Sigulda']],
            ['Liechtenstein', 'LI', '🇱🇮', ['Vaduz', 'Schaan', 'Triesen', 'Balzers']],
            ['Lithuania', 'LT', '🇱🇹', ['Vilnius', 'Kaunas', 'Klaipėda', 'Šiauliai', 'Trakai']],
            ['Luxembourg', 'LU', '🇱🇺', ['Luxembourg City', 'Esch-sur-Alzette', 'Differdange', 'Dudelange']],
            ['Malta', 'MT', '🇲🇹', ['Valletta', 'Sliema', 'St. Julian\'s', 'Mdina', 'Gozo', 'Mellieħa']],
            ['Moldova', 'MD', '🇲🇩', ['Chișinău', 'Tiraspol', 'Bălți', 'Cahul', 'Comrat']],
            ['Monaco', 'MC', '🇲🇨', ['Monaco', 'Monte Carlo', 'La Condamine', 'Fontvieille']],
            ['Montenegro', 'ME', '🇲🇪', ['Podgorica', 'Budva', 'Kotor', 'Herceg Novi', 'Tivat', 'Ulcinj']],
            ['North Macedonia', 'MK', '🇲🇰', ['Skopje', 'Ohrid', 'Bitola', 'Tetovo', 'Mavrovo']],
            ['San Marino', 'SM', '🇸🇲', ['San Marino', 'Borgo Maggiore', 'Serravalle', 'Domagnano']],
            ['Slovakia', 'SK', '🇸🇰', ['Bratislava', 'Košice', 'Žilina', 'High Tatras', 'Banská Bystrica']],
            ['Slovenia', 'SI', '🇸🇮', ['Ljubljana', 'Bled', 'Maribor', 'Piran', 'Kranjska Gora', 'Portorož']],
            ['Vatican City', 'VA', '🇻🇦', ['Vatican City']],

            // ─── Africa (full sweep) ───────────────────────────────────────
            ['South Africa', 'ZA', '🇿🇦', ['Cape Town', 'Johannesburg', 'Durban', 'Pretoria', 'Port Elizabeth', 'Stellenbosch', 'Kruger']],
            ['Nigeria', 'NG', '🇳🇬', ['Lagos', 'Abuja', 'Kano', 'Ibadan', 'Port Harcourt', 'Calabar']],
            ['Kenya', 'KE', '🇰🇪', ['Nairobi', 'Mombasa', 'Kisumu', 'Nakuru', 'Maasai Mara', 'Diani Beach']],
            ['Ethiopia', 'ET', '🇪🇹', ['Addis Ababa', 'Dire Dawa', 'Mekelle', 'Gondar', 'Lalibela', 'Bahir Dar']],
            ['Tanzania', 'TZ', '🇹🇿', ['Dar es Salaam', 'Dodoma', 'Arusha', 'Zanzibar', 'Mwanza', 'Serengeti', 'Kilimanjaro']],
            ['Uganda', 'UG', '🇺🇬', ['Kampala', 'Entebbe', 'Jinja', 'Mbarara', 'Bwindi', 'Murchison']],
            ['Ghana', 'GH', '🇬🇭', ['Accra', 'Kumasi', 'Tamale', 'Cape Coast', 'Takoradi']],
            ['Senegal', 'SN', '🇸🇳', ['Dakar', 'Saint-Louis', 'Touba', 'Thiès', 'Saly']],
            ['Côte d\'Ivoire', 'CI', '🇨🇮', ['Abidjan', 'Yamoussoukro', 'Bouaké', 'San-Pédro', 'Grand-Bassam']],
            ['Cameroon', 'CM', '🇨🇲', ['Yaoundé', 'Douala', 'Garoua', 'Bamenda', 'Kribi']],
            ['Angola', 'AO', '🇦🇴', ['Luanda', 'Huambo', 'Lobito', 'Benguela', 'Lubango']],
            ['Mozambique', 'MZ', '🇲🇿', ['Maputo', 'Beira', 'Nampula', 'Pemba', 'Tofo']],
            ['Madagascar', 'MG', '🇲🇬', ['Antananarivo', 'Toamasina', 'Antsirabe', 'Nosy Be', 'Morondava']],
            ['Mauritius', 'MU', '🇲🇺', ['Port Louis', 'Grand Baie', 'Flic en Flac', 'Belle Mare', 'Le Morne']],
            ['Seychelles', 'SC', '🇸🇨', ['Victoria', 'Mahé', 'Praslin', 'La Digue', 'Beau Vallon']],
            ['Zimbabwe', 'ZW', '🇿🇼', ['Harare', 'Bulawayo', 'Victoria Falls', 'Mutare', 'Kariba']],
            ['Zambia', 'ZM', '🇿🇲', ['Lusaka', 'Livingstone', 'Kitwe', 'Ndola', 'Kafue']],
            ['Botswana', 'BW', '🇧🇼', ['Gaborone', 'Maun', 'Francistown', 'Kasane', 'Chobe']],
            ['Namibia', 'NA', '🇳🇦', ['Windhoek', 'Swakopmund', 'Walvis Bay', 'Etosha', 'Sossusvlei']],
            ['Rwanda', 'RW', '🇷🇼', ['Kigali', 'Butare', 'Gisenyi', 'Volcanoes National Park', 'Akagera']],
            ['Burundi', 'BI', '🇧🇮', ['Bujumbura', 'Gitega', 'Ngozi', 'Rumonge']],
            ['Malawi', 'MW', '🇲🇼', ['Lilongwe', 'Blantyre', 'Mzuzu', 'Lake Malawi']],
            ['Lesotho', 'LS', '🇱🇸', ['Maseru', 'Teyateyaneng', 'Maputsoe', 'Hlotse']],
            ['Eswatini', 'SZ', '🇸🇿', ['Mbabane', 'Manzini', 'Lobamba', 'Hlatikulu']],
            ['Sudan', 'SD', '🇸🇩', ['Khartoum', 'Omdurman', 'Port Sudan', 'Kassala', 'Wad Madani']],
            ['South Sudan', 'SS', '🇸🇸', ['Juba', 'Wau', 'Malakal', 'Yei']],
            ['Somalia', 'SO', '🇸🇴', ['Mogadishu', 'Hargeisa', 'Bosaso', 'Kismayo']],
            ['Djibouti', 'DJ', '🇩🇯', ['Djibouti', 'Ali Sabieh', 'Tadjoura', 'Obock']],
            ['Eritrea', 'ER', '🇪🇷', ['Asmara', 'Massawa', 'Keren', 'Assab']],
            ['Libya', 'LY', '🇱🇾', ['Tripoli', 'Benghazi', 'Misrata', 'Sabha', 'Tobruk']],
            ['Mali', 'ML', '🇲🇱', ['Bamako', 'Timbuktu', 'Mopti', 'Sikasso', 'Gao']],
            ['Burkina Faso', 'BF', '🇧🇫', ['Ouagadougou', 'Bobo-Dioulasso', 'Koudougou', 'Banfora']],
            ['Niger', 'NE', '🇳🇪', ['Niamey', 'Zinder', 'Maradi', 'Agadez']],
            ['Chad', 'TD', '🇹🇩', ['N\'Djamena', 'Moundou', 'Sarh', 'Abéché']],
            ['Mauritania', 'MR', '🇲🇷', ['Nouakchott', 'Nouadhibou', 'Atar', 'Chinguetti']],
            ['Gambia', 'GM', '🇬🇲', ['Banjul', 'Serrekunda', 'Kanifing', 'Brikama']],
            ['Guinea', 'GN', '🇬🇳', ['Conakry', 'Kindia', 'Kankan', 'Labé']],
            ['Guinea-Bissau', 'GW', '🇬🇼', ['Bissau', 'Bafatá', 'Gabú', 'Bissorã']],
            ['Sierra Leone', 'SL', '🇸🇱', ['Freetown', 'Bo', 'Kenema', 'Makeni']],
            ['Liberia', 'LR', '🇱🇷', ['Monrovia', 'Buchanan', 'Gbarnga', 'Robertsport']],
            ['Togo', 'TG', '🇹🇬', ['Lomé', 'Sokodé', 'Kara', 'Atakpamé']],
            ['Benin', 'BJ', '🇧🇯', ['Cotonou', 'Porto-Novo', 'Parakou', 'Abomey', 'Ouidah']],
            ['Cape Verde', 'CV', '🇨🇻', ['Praia', 'Mindelo', 'Sal', 'Boa Vista', 'Santo Antão']],
            ['São Tomé and Príncipe', 'ST', '🇸🇹', ['São Tomé', 'Trindade', 'Santana', 'Príncipe']],
            ['Comoros', 'KM', '🇰🇲', ['Moroni', 'Mutsamudu', 'Fomboni']],
            ['Equatorial Guinea', 'GQ', '🇬🇶', ['Malabo', 'Bata', 'Ebebiyín']],
            ['Gabon', 'GA', '🇬🇦', ['Libreville', 'Port-Gentil', 'Franceville', 'Oyem']],
            ['Republic of Congo', 'CG', '🇨🇬', ['Brazzaville', 'Pointe-Noire', 'Dolisie', 'Ouesso']],
            ['DR Congo', 'CD', '🇨🇩', ['Kinshasa', 'Lubumbashi', 'Goma', 'Bukavu', 'Mbuji-Mayi']],
            ['Central African Republic', 'CF', '🇨🇫', ['Bangui', 'Bimbo', 'Berbérati', 'Bambari']],

            // ─── North America ─────────────────────────────────────────────
            ['United States', 'US', '🇺🇸', ['New York', 'Los Angeles', 'Las Vegas', 'Miami', 'Chicago', 'San Francisco', 'Orlando', 'Honolulu', 'Boston', 'Washington D.C.']],
            ['Canada', 'CA', '🇨🇦', ['Toronto', 'Vancouver', 'Montreal', 'Ottawa', 'Calgary', 'Quebec City', 'Banff', 'Niagara Falls']],
            ['Mexico', 'MX', '🇲🇽', ['Mexico City', 'Cancún', 'Playa del Carmen', 'Tulum', 'Puerto Vallarta', 'Cabo San Lucas', 'Oaxaca', 'Guadalajara', 'Mérida']],
            ['Cuba', 'CU', '🇨🇺', ['Havana', 'Varadero', 'Trinidad', 'Santiago de Cuba', 'Cayo Coco', 'Holguín']],
            ['Dominican Republic', 'DO', '🇩🇴', ['Santo Domingo', 'Punta Cana', 'Puerto Plata', 'La Romana', 'Samaná']],
            ['Jamaica', 'JM', '🇯🇲', ['Kingston', 'Montego Bay', 'Negril', 'Ocho Rios', 'Port Antonio']],
            ['Bahamas', 'BS', '🇧🇸', ['Nassau', 'Paradise Island', 'Freeport', 'Exuma', 'Eleuthera']],
            ['Barbados', 'BB', '🇧🇧', ['Bridgetown', 'Holetown', 'Speightstown', 'Oistins']],
            ['Antigua and Barbuda', 'AG', '🇦🇬', ['St. John\'s', 'English Harbour', 'Falmouth Harbour', 'Codrington']],
            ['Saint Lucia', 'LC', '🇱🇨', ['Castries', 'Soufrière', 'Gros Islet', 'Vieux Fort']],
            ['Saint Kitts and Nevis', 'KN', '🇰🇳', ['Basseterre', 'Charlestown', 'Sandy Point Town']],
            ['Saint Vincent and the Grenadines', 'VC', '🇻🇨', ['Kingstown', 'Bequia', 'Mustique', 'Canouan']],
            ['Grenada', 'GD', '🇬🇩', ['St. George\'s', 'Grand Anse', 'Carriacou']],
            ['Dominica', 'DM', '🇩🇲', ['Roseau', 'Portsmouth', 'Marigot']],
            ['Trinidad and Tobago', 'TT', '🇹🇹', ['Port of Spain', 'San Fernando', 'Scarborough', 'Crown Point']],
            ['Haiti', 'HT', '🇭🇹', ['Port-au-Prince', 'Cap-Haïtien', 'Jacmel', 'Les Cayes']],
            ['Belize', 'BZ', '🇧🇿', ['Belize City', 'San Pedro', 'Caye Caulker', 'San Ignacio', 'Placencia']],
            ['Costa Rica', 'CR', '🇨🇷', ['San José', 'Tamarindo', 'Manuel Antonio', 'Monteverde', 'La Fortuna', 'Jacó']],
            ['Panama', 'PA', '🇵🇦', ['Panama City', 'Bocas del Toro', 'San Blas', 'David', 'Boquete']],
            ['Guatemala', 'GT', '🇬🇹', ['Guatemala City', 'Antigua', 'Tikal', 'Lake Atitlán', 'Quetzaltenango']],
            ['Honduras', 'HN', '🇭🇳', ['Tegucigalpa', 'Roatán', 'San Pedro Sula', 'La Ceiba', 'Copán']],
            ['El Salvador', 'SV', '🇸🇻', ['San Salvador', 'Santa Ana', 'San Miguel', 'La Libertad']],
            ['Nicaragua', 'NI', '🇳🇮', ['Managua', 'Granada', 'León', 'San Juan del Sur', 'Ometepe']],

            // ─── South America ─────────────────────────────────────────────
            ['Brazil', 'BR', '🇧🇷', ['Rio de Janeiro', 'São Paulo', 'Salvador', 'Brasília', 'Florianópolis', 'Fortaleza', 'Manaus', 'Foz do Iguaçu', 'Recife']],
            ['Argentina', 'AR', '🇦🇷', ['Buenos Aires', 'Córdoba', 'Bariloche', 'Mendoza', 'Mar del Plata', 'Ushuaia', 'Salta', 'Iguazú']],
            ['Chile', 'CL', '🇨🇱', ['Santiago', 'Valparaíso', 'Viña del Mar', 'Atacama', 'Patagonia', 'Punta Arenas', 'Easter Island']],
            ['Peru', 'PE', '🇵🇪', ['Lima', 'Cusco', 'Machu Picchu', 'Arequipa', 'Puno', 'Iquitos', 'Trujillo']],
            ['Colombia', 'CO', '🇨🇴', ['Bogotá', 'Medellín', 'Cartagena', 'Cali', 'Santa Marta', 'San Andrés']],
            ['Venezuela', 'VE', '🇻🇪', ['Caracas', 'Maracaibo', 'Valencia', 'Maracay', 'Mérida', 'Margarita Island']],
            ['Ecuador', 'EC', '🇪🇨', ['Quito', 'Guayaquil', 'Cuenca', 'Galápagos', 'Baños', 'Otavalo']],
            ['Bolivia', 'BO', '🇧🇴', ['La Paz', 'Santa Cruz', 'Cochabamba', 'Sucre', 'Uyuni', 'Copacabana']],
            ['Uruguay', 'UY', '🇺🇾', ['Montevideo', 'Punta del Este', 'Colonia del Sacramento', 'Salto']],
            ['Paraguay', 'PY', '🇵🇾', ['Asunción', 'Ciudad del Este', 'Encarnación', 'Pedro Juan Caballero']],
            ['Guyana', 'GY', '🇬🇾', ['Georgetown', 'Linden', 'New Amsterdam']],
            ['Suriname', 'SR', '🇸🇷', ['Paramaribo', 'Lelydorp', 'Nieuw Nickerie']],

            // ─── Oceania ───────────────────────────────────────────────────
            ['Australia', 'AU', '🇦🇺', ['Sydney', 'Melbourne', 'Brisbane', 'Perth', 'Gold Coast', 'Cairns', 'Adelaide', 'Hobart', 'Darwin', 'Canberra']],
            ['New Zealand', 'NZ', '🇳🇿', ['Auckland', 'Wellington', 'Queenstown', 'Christchurch', 'Rotorua', 'Dunedin', 'Napier']],
            ['Fiji', 'FJ', '🇫🇯', ['Suva', 'Nadi', 'Lautoka', 'Denarau Island', 'Mamanuca Islands']],
            ['Papua New Guinea', 'PG', '🇵🇬', ['Port Moresby', 'Lae', 'Mount Hagen', 'Madang']],
            ['Solomon Islands', 'SB', '🇸🇧', ['Honiara', 'Auki', 'Gizo', 'Kirakira']],
            ['Vanuatu', 'VU', '🇻🇺', ['Port Vila', 'Luganville', 'Tanna', 'Espiritu Santo']],
            ['Samoa', 'WS', '🇼🇸', ['Apia', 'Salelologa', 'Lalomanu']],
            ['Tonga', 'TO', '🇹🇴', ['Nukuʻalofa', 'Neiafu', 'Pangai']],
            ['Kiribati', 'KI', '🇰🇮', ['Tarawa', 'Bairiki', 'Betio']],
            ['Marshall Islands', 'MH', '🇲🇭', ['Majuro', 'Ebeye', 'Jaluit']],
            ['Micronesia', 'FM', '🇫🇲', ['Palikir', 'Weno', 'Kolonia']],
            ['Palau', 'PW', '🇵🇼', ['Ngerulmud', 'Koror', 'Airai']],
            ['Nauru', 'NR', '🇳🇷', ['Yaren']],
            ['Tuvalu', 'TV', '🇹🇻', ['Funafuti', 'Vaitupu', 'Niutao']],
        ];

        $added = 0;
        foreach ($countries as [$name, $iso, $flag, $cities]) {
            $country = $this->upsertCountry($name, $iso, $flag);
            foreach ($cities as $cityName) {
                $this->upsertCity($cityName, $country);
            }
            $added++;
        }

        $this->command?->info("WorldExtensionSeeder: processed {$added} countries.");
    }

    private function upsertCountry(string $name, string $iso, string $flag): Location
    {
        $loc = Location::firstOrNew([
            'type' => 'country',
            'name' => $name,
        ]);

        if (! $loc->exists) {
            $loc->slug = Str::slug($name);
            $loc->parent_id = null;
            $loc->depth = 0;
            $loc->path = Str::slug($name);
            $loc->is_active = true;
        }
        $loc->country_code = strtoupper($iso);
        $loc->flag_emoji = $flag;
        $loc->save();

        return $loc;
    }

    private function upsertCity(string $name, Location $country): Location
    {
        $loc = Location::firstOrNew([
            'type' => 'city',
            'name' => $name,
            'parent_id' => $country->id,
        ]);

        if (! $loc->exists) {
            $loc->slug = Str::slug($country->name.'-'.$name);
            $loc->depth = 1; // direct child of country (no intermediate region)
            $loc->path = $country->path.'/'.$loc->id; // path patched after save
            $loc->is_active = true;
        }
        $loc->country_code = $country->country_code;
        $loc->save();

        // Patch path with own id once we have it (firstOrNew didn't know id yet).
        if (! str_ends_with($loc->path ?? '', '/'.$loc->id)) {
            $loc->path = $country->path.'/'.$loc->id;
            $loc->save();
        }

        return $loc;
    }
}
