<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Role;
use App\Models\SellerFxRate;
use App\Models\User;
use Database\Seeders\RbacBootstrapSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * B2a — admin CRUD for the seller FX-rate setting (seller_fx_rates).
 *
 * Covers the two write surfaces + STRICT tenant isolation:
 *   - super-admin sets the platform default and any operator/agent row
 *     (POST/PUT /api/platform-admin/seller-fx-rates);
 *   - operator (company_admin) and agent (role `agent`) upsert their OWN row
 *     (PUT /api/operator/seller-fx-rate) — company_id PINNED from membership,
 *     never trusted from the body;
 *   - server-side validation (bad source / negative margin / manual without
 *     rates / currency off the allow-list).
 */
class SellerFxRateAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacBootstrapSeeder::class);
    }

    private function superAdmin(): User
    {
        return User::query()->where('email', 'admin@zulu.local')->firstOrFail();
    }

    private function company(string $name, string $type): Company
    {
        return Company::query()->create(['name' => $name, 'type' => $type]);
    }

    private function memberOf(Company $company, string $roleName): User
    {
        $user = User::factory()->create();
        $user->companies()->attach($company->id, [
            'role_id' => (int) Role::query()->where('name', $roleName)->value('id'),
        ]);

        return $user->fresh();
    }

    // ── Super-admin platform / any-scope ─────────────────────────────────

    public function test_super_sets_platform_default(): void
    {
        Sanctum::actingAs($this->superAdmin());

        $this->postJson('/api/platform-admin/seller-fx-rates', [
            'scope' => SellerFxRate::SCOPE_PLATFORM,
            'source' => 'cba',
            'margin' => 2.5,
        ])
            ->assertCreated()
            ->assertJsonPath('data.scope', 'platform')
            ->assertJsonPath('data.company_id', null)
            ->assertJsonPath('data.target_currency', 'AMD')
            ->assertJsonPath('data.margin', 2.5);

        $this->assertDatabaseHas('seller_fx_rates', [
            'scope' => 'platform',
            'company_id' => null,
            'target_currency' => 'AMD',
            'source' => 'cba',
        ]);
    }

    public function test_super_sets_arbitrary_operator_row(): void
    {
        $operator = $this->company('Op Co', 'operator');
        Sanctum::actingAs($this->superAdmin());

        $this->postJson('/api/platform-admin/seller-fx-rates', [
            'scope' => SellerFxRate::SCOPE_OPERATOR,
            'company_id' => $operator->id,
            'source' => 'manual',
            'margin' => 1,
            'manual_rates' => ['usd' => 400, 'eur' => 430],
        ])
            ->assertCreated()
            ->assertJsonPath('data.scope', 'operator')
            ->assertJsonPath('data.company_id', $operator->id)
            // currencies upper-cased on persist
            ->assertJsonPath('data.manual_rates.USD', '400')
            ->assertJsonPath('data.manual_rates.EUR', '430');

        $this->assertDatabaseHas('seller_fx_rates', [
            'scope' => 'operator',
            'company_id' => $operator->id,
            'source' => 'manual',
        ]);
    }

    public function test_super_post_is_idempotent_upsert_on_unique_key(): void
    {
        Sanctum::actingAs($this->superAdmin());

        $first = $this->postJson('/api/platform-admin/seller-fx-rates', [
            'scope' => SellerFxRate::SCOPE_PLATFORM,
            'source' => 'cba',
            'margin' => 1,
        ])->assertCreated()->json('data.id');

        // Re-post same key updates the SAME row (no 23505).
        $second = $this->postJson('/api/platform-admin/seller-fx-rates', [
            'scope' => SellerFxRate::SCOPE_PLATFORM,
            'source' => 'cba',
            'margin' => 9,
        ])->assertCreated()->json('data.id');

        $this->assertSame($first, $second);
        $this->assertSame(1, SellerFxRate::query()->where('scope', 'platform')->count());
        $this->assertSame(9.0, (float) SellerFxRate::query()->find($first)->margin);
    }

    public function test_super_update_edits_existing_row_in_place(): void
    {
        $row = SellerFxRate::query()->create([
            'scope' => SellerFxRate::SCOPE_PLATFORM,
            'company_id' => null,
            'target_currency' => 'AMD',
            'source' => 'cba',
            'margin' => '1',
            'is_active' => true,
        ]);

        Sanctum::actingAs($this->superAdmin());

        $this->putJson("/api/platform-admin/seller-fx-rates/{$row->id}", [
            'source' => 'cba',
            'margin' => 7,
            'is_active' => false,
        ])
            ->assertOk()
            ->assertJsonPath('data.id', $row->id)
            ->assertJsonPath('data.is_active', false);

        $this->assertSame(7.0, (float) SellerFxRate::query()->find($row->id)->margin);
    }

    public function test_operator_cannot_reach_platform_admin_write(): void
    {
        $operator = $this->company('Op Co', 'operator');
        Sanctum::actingAs($this->memberOf($operator, 'company_admin'));

        // EnsurePlatformAdmin blocks any POST under platform-admin for operators.
        $this->postJson('/api/platform-admin/seller-fx-rates', [
            'scope' => SellerFxRate::SCOPE_PLATFORM,
            'source' => 'cba',
            'margin' => 1,
        ])->assertForbidden();
    }

    // ── Operator / agent self-service ────────────────────────────────────

    public function test_operator_upserts_own_setting(): void
    {
        $operator = $this->company('Op Co', 'operator');
        Sanctum::actingAs($this->memberOf($operator, 'company_admin'));

        $this->putJson('/api/operator/seller-fx-rate', [
            'source' => 'cba',
            'margin' => 3,
        ])
            ->assertOk()
            ->assertJsonPath('data.scope', 'operator')
            ->assertJsonPath('data.company_id', $operator->id);

        $this->assertDatabaseHas('seller_fx_rates', [
            'scope' => 'operator',
            'company_id' => $operator->id,
        ]);
        $this->assertSame(
            3.0,
            (float) SellerFxRate::query()
                ->where('scope', 'operator')->where('company_id', $operator->id)->value('margin'),
        );

        // Read-back returns the same row.
        $this->getJson('/api/operator/seller-fx-rate')
            ->assertOk()
            ->assertJsonPath('data.company_id', $operator->id);
    }

    public function test_agent_upserts_own_setting_via_agent_aware_gate(): void
    {
        // Proves the write is NOT gated on a .manage permission (agents hold
        // only .view) — canManageSellerStatus admits the agency owner.
        $agency = $this->company('Agency Co', 'agency');
        Sanctum::actingAs($this->memberOf($agency, 'agent'));

        $this->putJson('/api/operator/seller-fx-rate', [
            'source' => 'manual',
            'margin' => 0,
            'manual_rates' => ['USD' => 402.5],
        ])
            ->assertOk()
            ->assertJsonPath('data.scope', 'agent')
            ->assertJsonPath('data.company_id', $agency->id)
            ->assertJsonPath('data.manual_rates.USD', '402.5');

        $this->assertDatabaseHas('seller_fx_rates', [
            'scope' => 'agent',
            'company_id' => $agency->id,
        ]);
    }

    public function test_operator_cannot_write_another_companys_row(): void
    {
        // The body company_id is NEVER honored for a non-super caller: the row
        // is keyed to the AUTHENTICATED company, so the foreign company's row
        // stays untouched.
        $own = $this->company('Own Op', 'operator');
        $other = $this->company('Other Op', 'operator');
        Sanctum::actingAs($this->memberOf($own, 'company_admin'));

        $this->putJson('/api/operator/seller-fx-rate', [
            'company_id' => $other->id, // attacker-supplied — must be ignored
            'scope' => SellerFxRate::SCOPE_OPERATOR,
            'source' => 'cba',
            'margin' => 5,
        ])
            ->assertOk()
            ->assertJsonPath('data.company_id', $own->id);

        // Own row created; the other company's row was never written.
        $this->assertDatabaseHas('seller_fx_rates', ['company_id' => $own->id]);
        $this->assertDatabaseMissing('seller_fx_rates', ['company_id' => $other->id]);
    }

    public function test_user_with_no_company_gets_404_on_self_upsert(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user->fresh());

        $this->putJson('/api/operator/seller-fx-rate', [
            'source' => 'cba',
            'margin' => 1,
        ])->assertStatus(404);
    }

    public function test_unsupported_company_type_cannot_set_fx(): void
    {
        $airline = $this->company('Air Co', 'airline');
        Sanctum::actingAs($this->memberOf($airline, 'company_admin'));

        $this->putJson('/api/operator/seller-fx-rate', [
            'source' => 'cba',
            'margin' => 1,
        ])->assertStatus(422);
    }

    // ── Validation ───────────────────────────────────────────────────────

    public function test_validation_rejects_bad_source(): void
    {
        $operator = $this->company('Op Co', 'operator');
        Sanctum::actingAs($this->memberOf($operator, 'company_admin'));

        $this->putJson('/api/operator/seller-fx-rate', [
            'source' => 'ecb', // not cba|manual
            'margin' => 1,
        ])->assertStatus(422);
    }

    public function test_validation_rejects_negative_margin(): void
    {
        $operator = $this->company('Op Co', 'operator');
        Sanctum::actingAs($this->memberOf($operator, 'company_admin'));

        $this->putJson('/api/operator/seller-fx-rate', [
            'source' => 'cba',
            'margin' => -1,
        ])->assertStatus(422);
    }

    public function test_validation_rejects_manual_without_rates(): void
    {
        $operator = $this->company('Op Co', 'operator');
        Sanctum::actingAs($this->memberOf($operator, 'company_admin'));

        $this->putJson('/api/operator/seller-fx-rate', [
            'source' => 'manual',
            'margin' => 1,
            // manual_rates omitted
        ])->assertStatus(422);

        $this->putJson('/api/operator/seller-fx-rate', [
            'source' => 'manual',
            'margin' => 1,
            'manual_rates' => [], // empty
        ])->assertStatus(422);
    }

    public function test_validation_rejects_currency_off_allow_list(): void
    {
        $operator = $this->company('Op Co', 'operator');
        Sanctum::actingAs($this->memberOf($operator, 'company_admin'));

        // target_currency outside USD/EUR/AMD.
        $this->putJson('/api/operator/seller-fx-rate', [
            'source' => 'cba',
            'margin' => 1,
            'target_currency' => 'GBP',
        ])->assertStatus(422);

        // manual_rates key outside the allow-list.
        $this->putJson('/api/operator/seller-fx-rate', [
            'source' => 'manual',
            'margin' => 1,
            'manual_rates' => ['GBP' => 500],
        ])->assertStatus(422);
    }

    public function test_validation_rejects_non_positive_manual_rate(): void
    {
        $operator = $this->company('Op Co', 'operator');
        Sanctum::actingAs($this->memberOf($operator, 'company_admin'));

        $this->putJson('/api/operator/seller-fx-rate', [
            'source' => 'manual',
            'margin' => 1,
            'manual_rates' => ['USD' => 0],
        ])->assertStatus(422);
    }
}
