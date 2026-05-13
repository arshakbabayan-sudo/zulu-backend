<?php

namespace Tests\Feature;

use App\Models\FooterColumn;
use App\Models\FooterLink;
use App\Models\HeaderMenuItem;
use App\Models\User;
use Database\Seeders\RbacBootstrapSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class HeaderFooterApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacBootstrapSeeder::class);
        // Wipe rows seeded by 2026_05_13_002200_seed_initial_header_footer
        // so each test starts from a known empty state.
        \DB::table('footer_links')->delete();
        \DB::table('footer_columns')->delete();
        \DB::table('header_menu_items')->delete();
    }

    /** ─────────── Header menu ─────────── */
    public function test_public_can_read_localized_header_menu_tree(): void
    {
        $about = HeaderMenuItem::create([
            'label_en' => 'About', 'label_ru' => 'О нас', 'label_hy' => 'Մեր մասին',
            'url' => '/about', 'position' => 1, 'is_visible' => true,
        ]);
        $destinations = HeaderMenuItem::create([
            'label_en' => 'Destinations', 'label_ru' => 'Направления', 'label_hy' => 'Ուղղություններ',
            'url' => '#', 'position' => 2, 'is_visible' => true,
        ]);
        HeaderMenuItem::create([
            'parent_id' => $destinations->id,
            'label_en' => 'Flights', 'label_hy' => 'Թռիչքներ',
            'url' => '/flights', 'position' => 1, 'is_visible' => true,
        ]);
        HeaderMenuItem::create([
            'label_en' => 'Hidden item',
            'url' => '/hidden', 'position' => 3, 'is_visible' => false,
        ]);

        $resp = $this->getJson('/api/header-menu?lang=hy')->assertOk();

        $items = $resp->json('data.items');
        $this->assertCount(2, $items); // Hidden one excluded
        $this->assertSame('Մեր մասին', $items[0]['label']);
        $this->assertSame('Ուղղություններ', $items[1]['label']);
        $this->assertCount(1, $items[1]['children']);
        $this->assertSame('Թռիչքներ', $items[1]['children'][0]['label']);
        $this->assertSame('hy', $resp->json('data.lang'));

        // Sanity check unknown id is not present
        $this->assertSame($about->id, $items[0]['id']);
    }

    public function test_admin_can_sync_header_menu_with_new_and_existing_items(): void
    {
        $admin = User::query()->where('email', 'admin@zulu.local')->firstOrFail();
        Sanctum::actingAs($admin);

        // Existing row that should be updated
        $existing = HeaderMenuItem::create([
            'label_en' => 'Old Label', 'url' => '/old', 'position' => 1, 'is_visible' => true,
        ]);
        // Another row that should be deleted (not in payload)
        $toDelete = HeaderMenuItem::create([
            'label_en' => 'Goodbye', 'url' => '/goodbye', 'position' => 99, 'is_visible' => true,
        ]);

        $payload = [
            'items' => [
                ['id' => $existing->id, 'parent_id' => null, 'label_en' => 'New Label', 'label_hy' => 'Նոր', 'url' => '/new', 'position' => 1, 'is_visible' => true],
                ['parent_id' => null, 'label_en' => 'Brand New', 'url' => '/brand-new', 'position' => 2, 'is_visible' => true],
            ],
        ];

        $resp = $this->putJson('/api/platform-admin/header-menu', $payload)->assertOk();

        $rows = $resp->json('data.items');
        $this->assertCount(2, $rows);
        $this->assertDatabaseMissing('header_menu_items', ['id' => $toDelete->id]);
        $this->assertDatabaseHas('header_menu_items', ['id' => $existing->id, 'label_en' => 'New Label', 'url' => '/new']);
        $this->assertDatabaseHas('header_menu_items', ['label_en' => 'Brand New']);
    }

    public function test_admin_can_create_nested_parent_child_in_one_sync(): void
    {
        $admin = User::query()->where('email', 'admin@zulu.local')->firstOrFail();
        Sanctum::actingAs($admin);

        $payload = [
            'items' => [
                ['id' => -1, 'parent_id' => null, 'label_en' => 'Destinations', 'url' => '#', 'position' => 1, 'is_visible' => true],
                ['id' => -2, 'parent_id' => -1, 'label_en' => 'Flights', 'url' => '/flights', 'position' => 1, 'is_visible' => true],
                ['id' => -3, 'parent_id' => -1, 'label_en' => 'Hotels', 'url' => '/hotels', 'position' => 2, 'is_visible' => true],
            ],
        ];

        $this->putJson('/api/platform-admin/header-menu', $payload)->assertOk();

        $parent = HeaderMenuItem::query()->where('label_en', 'Destinations')->whereNull('parent_id')->firstOrFail();
        $this->assertCount(2, $parent->children);
        $this->assertSame('Flights', $parent->children[0]->label_en);
    }

    public function test_non_admin_cannot_sync_header(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->putJson('/api/platform-admin/header-menu', ['items' => []])->assertStatus(403);
    }

    /** ─────────── Footer ─────────── */
    public function test_public_can_read_localized_footer_columns_with_visible_links(): void
    {
        $col = FooterColumn::create([
            'slug' => 'help', 'title_en' => 'Help', 'title_hy' => 'Օգնություն',
            'position' => 1, 'is_visible' => true,
        ]);
        FooterLink::create([
            'column_id' => $col->id, 'label_en' => 'FAQ', 'label_hy' => 'ՀՏՀ',
            'url' => '/faq', 'position' => 1, 'is_visible' => true,
        ]);
        FooterLink::create([
            'column_id' => $col->id, 'label_en' => 'Hidden',
            'url' => '/hidden', 'position' => 2, 'is_visible' => false,
        ]);

        $resp = $this->getJson('/api/footer?lang=hy')->assertOk();
        $cols = $resp->json('data.columns');
        $this->assertCount(1, $cols);
        $this->assertSame('Օգնություն', $cols[0]['title']);
        $this->assertCount(1, $cols[0]['links']);
        $this->assertSame('ՀՏՀ', $cols[0]['links'][0]['label']);
    }

    public function test_admin_can_sync_footer_full_replace(): void
    {
        $admin = User::query()->where('email', 'admin@zulu.local')->firstOrFail();
        Sanctum::actingAs($admin);

        $oldCol = FooterColumn::create([
            'slug' => 'temp', 'title_en' => 'Temp', 'position' => 1, 'is_visible' => true,
        ]);
        FooterLink::create([
            'column_id' => $oldCol->id, 'label_en' => 'Old', 'url' => '/old',
            'position' => 1, 'is_visible' => true,
        ]);

        $payload = [
            'columns' => [
                [
                    'slug' => 'travel',
                    'title_en' => 'Travel', 'title_hy' => 'Ճանապարհորդություն',
                    'position' => 1, 'is_visible' => true,
                    'links' => [
                        ['label_en' => 'Flights', 'label_hy' => 'Թռիչքներ', 'url' => '/flights', 'position' => 1, 'is_visible' => true],
                        ['label_en' => 'Hotels', 'label_hy' => 'Հյուրանոցներ', 'url' => '/hotels', 'position' => 2, 'is_visible' => true],
                    ],
                ],
            ],
        ];

        $this->putJson('/api/platform-admin/footer', $payload)->assertOk();

        $this->assertDatabaseMissing('footer_columns', ['id' => $oldCol->id]);
        $this->assertDatabaseHas('footer_columns', ['slug' => 'travel', 'title_en' => 'Travel']);
        $this->assertSame(2, FooterLink::query()->count());
    }

    public function test_non_admin_cannot_sync_footer(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->putJson('/api/platform-admin/footer', ['columns' => []])->assertStatus(403);
    }
}
