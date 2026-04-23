<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\User;
use Database\Seeders\RbacBootstrapSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminLocationControllerAdapterTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    private Location $countryA;

    private Location $countryB;

    private Location $regionA;

    private Location $regionB;

    private Location $cityA;

    private Location $cityB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacBootstrapSeeder::class);
        $this->superAdmin = User::query()->where('email', 'admin@zulu.local')->firstOrFail();

        $this->countryA = $this->createNode('Armenia', Location::TYPE_COUNTRY, null, [
            'code' => 'AM',
            'country_code' => 'AM',
            'sort_order' => 1,
            'flag_emoji' => "\u{1F1E6}\u{1F1F2}",
            'is_active' => true,
        ]);
        $this->countryB = $this->createNode('Georgia', Location::TYPE_COUNTRY, null, [
            'code' => 'GE',
            'country_code' => 'GE',
            'sort_order' => 2,
            'is_active' => true,
        ]);

        $this->regionA = $this->createNode('Yerevan Region', Location::TYPE_REGION, $this->countryA, [
            'country_code' => 'AM',
            'sort_order' => 1,
            'is_active' => true,
        ]);
        $this->regionB = $this->createNode('Tbilisi Region', Location::TYPE_REGION, $this->countryB, [
            'country_code' => 'GE',
            'sort_order' => 1,
            'is_active' => true,
        ]);

        $this->cityA = $this->createNode('Yerevan', Location::TYPE_CITY, $this->regionA, [
            'country_code' => 'AM',
            'sort_order' => 1,
            'latitude' => 40.1792,
            'longitude' => 44.4991,
            'is_active' => true,
        ]);
        $this->cityB = $this->createNode('Tbilisi', Location::TYPE_CITY, $this->regionB, [
            'country_code' => 'GE',
            'sort_order' => 1,
            'latitude' => 41.6938,
            'longitude' => 44.8015,
            'is_active' => true,
        ]);
    }

    public function test_countries_endpoint_returns_legacy_shape_from_location_tree(): void
    {
        $response = $this->getJson('/api/locations/countries', $this->authHeaders($this->superAdmin));

        $response->assertOk()
            ->assertJsonPath('success', true);

        $rows = $response->json('data');
        $this->assertIsArray($rows);
        $this->assertNotEmpty($rows);
        $this->assertArrayHasKey('id', $rows[0]);
        $this->assertArrayHasKey('name', $rows[0]);
        $this->assertArrayHasKey('code', $rows[0]);
        $this->assertArrayHasKey('regions_count', $rows[0]);

        $country = collect($rows)->firstWhere('id', $this->countryA->id);
        $this->assertNotNull($country);
        $this->assertSame('Armenia', $country['name']);
        $this->assertSame('AM', $country['code']);
        $this->assertSame(1, $country['regions_count']);
    }

    public function test_regions_endpoint_returns_only_children_of_given_country(): void
    {
        $response = $this->getJson(
            '/api/locations/countries/'.$this->countryA->id.'/regions',
            $this->authHeaders($this->superAdmin)
        );

        $response->assertOk()
            ->assertJsonPath('success', true);

        $rows = $response->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame($this->regionA->id, $rows[0]['id']);
        $this->assertSame($this->countryA->id, $rows[0]['country_id']);
        $this->assertSame('Yerevan Region', $rows[0]['name']);
    }

    public function test_cities_endpoint_returns_only_children_of_given_region(): void
    {
        $response = $this->getJson(
            '/api/locations/regions/'.$this->regionA->id.'/cities',
            $this->authHeaders($this->superAdmin)
        );

        $response->assertOk()
            ->assertJsonPath('success', true);

        $rows = $response->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame($this->cityA->id, $rows[0]['id']);
        $this->assertSame($this->regionA->id, $rows[0]['region_id']);
        $this->assertSame($this->countryA->id, $rows[0]['country_id']);
        $this->assertSame('Yerevan', $rows[0]['name']);
    }

    public function test_countries_endpoint_requires_authentication(): void
    {
        $this->getJson('/api/locations/countries')->assertUnauthorized();
    }

    /**
     * @return array<string, string>
     */
    private function authHeaders(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function createNode(string $name, string $type, ?Location $parent, array $attrs = []): Location
    {
        $node = new Location;
        $node->name = $name;
        $node->type = $type;
        $node->slug = strtolower(str_replace(' ', '-', $name));
        $node->parent_id = $parent?->id;
        $node->depth = $parent !== null ? ((int) $parent->depth + 1) : 0;
        $node->path = '';
        $node->save();

        $node->path = $parent !== null
            ? trim((string) ($parent->path ?: $parent->id), '/').'/'.$node->id
            : (string) $node->id;

        foreach ($attrs as $key => $value) {
            $node->{$key} = $value;
        }

        $node->save();

        return $node->fresh();
    }
}
