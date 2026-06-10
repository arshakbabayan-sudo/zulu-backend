<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Role;
use App\Models\TimeOffRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * PATCH /api/time-off/{id} — the work-hours "edit".
 *
 * Auth mirrors store()/decide(): the creator may edit their OWN pending entry;
 * a company manager (member of the entry's company) or a super admin may edit
 * even a decided entry; anyone else is 403'd.
 */
class TimeOffUpdateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['super_admin', 'company_admin'] as $r) {
            Role::query()->firstOrCreate(['name' => $r]);
        }
    }

    private function makeEntry(int $companyId, int $userId, string $status = 'pending'): TimeOffRecord
    {
        return TimeOffRecord::query()->create([
            'company_id' => $companyId,
            'user_id' => $userId,
            'type' => 'vacation',
            'starts_on' => '2026-07-01',
            'ends_on' => '2026-07-05',
            'hours_total' => 8,
            'notes' => 'original',
            'status' => $status,
        ]);
    }

    public function test_owner_can_update_pending_entry(): void
    {
        $company = Company::query()->create(['name' => 'TO A', 'type' => 'operator']);
        $owner = User::factory()->create();
        $owner->companies()->attach($company->id, ['role_id' => Role::query()->where('name', 'company_admin')->value('id')]);

        $entry = $this->makeEntry($company->id, $owner->id);

        Sanctum::actingAs($owner->fresh());
        $res = $this->patchJson("/api/time-off/{$entry->id}", [
            'type' => 'sick',
            'starts_on' => '2026-07-02',
            'ends_on' => '2026-07-06',
            'hours_total' => 16,
            'notes' => 'edited',
        ])->assertOk();

        $this->assertTrue($res->json('success'));
        $this->assertSame('sick', $res->json('data.type'));
        $this->assertSame('2026-07-02', $res->json('data.starts_on'));
        $this->assertSame('edited', $res->json('data.notes'));
        $this->assertSame('pending', $res->json('data.status'));

        $this->assertDatabaseHas('time_off_records', [
            'id' => $entry->id, 'type' => 'sick', 'notes' => 'edited',
        ]);
    }

    public function test_owner_cannot_update_decided_entry(): void
    {
        $company = Company::query()->create(['name' => 'TO B', 'type' => 'operator']);
        $owner = User::factory()->create();
        $owner->companies()->attach($company->id, ['role_id' => Role::query()->where('name', 'company_admin')->value('id')]);

        // Owner is a plain creator here (not a manager of the company → no
        // membership), so a decided entry must be locked.
        $plainCreator = User::factory()->create();
        $entry = $this->makeEntry($company->id, $plainCreator->id, 'approved');

        Sanctum::actingAs($plainCreator->fresh());
        $this->patchJson("/api/time-off/{$entry->id}", ['notes' => 'late edit'])
            ->assertStatus(422);

        $this->assertDatabaseHas('time_off_records', ['id' => $entry->id, 'notes' => 'original']);
    }

    public function test_manager_can_update_decided_entry(): void
    {
        $company = Company::query()->create(['name' => 'TO C', 'type' => 'operator']);
        $manager = User::factory()->create();
        $manager->companies()->attach($company->id, ['role_id' => Role::query()->where('name', 'company_admin')->value('id')]);

        $employee = User::factory()->create();
        $entry = $this->makeEntry($company->id, $employee->id, 'approved');

        Sanctum::actingAs($manager->fresh());
        $res = $this->patchJson("/api/time-off/{$entry->id}", ['notes' => 'manager fix'])
            ->assertOk();

        $this->assertSame('manager fix', $res->json('data.notes'));
    }

    public function test_non_owner_non_member_is_forbidden(): void
    {
        $company = Company::query()->create(['name' => 'TO D', 'type' => 'operator']);
        $employee = User::factory()->create();
        $entry = $this->makeEntry($company->id, $employee->id);

        $stranger = User::factory()->create(); // no membership in the entry's company
        Sanctum::actingAs($stranger->fresh());

        $this->patchJson("/api/time-off/{$entry->id}", ['notes' => 'hax'])
            ->assertStatus(403);

        $this->assertDatabaseHas('time_off_records', ['id' => $entry->id, 'notes' => 'original']);
    }
}
