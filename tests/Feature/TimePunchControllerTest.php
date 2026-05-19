<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Role;
use App\Models\TimePunch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Feature tests for time punches — clock in / clock out shift attendance.
 *
 * Critical behavior locked down:
 *   - clock-in opens a row with punched_in_at=now, source=self
 *   - second clock-in while a shift is open returns 409 (no concurrent shifts)
 *   - clock-out stamps punched_out_at and denormalises minutes_worked
 *   - second clock-out on a closed shift returns 409
 *   - index is company-scoped for non-super admins
 *   - manager store requires punched_out_at to be after punched_in_at
 */
class TimePunchControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_caller_is_rejected(): void
    {
        $this->getJson('/api/time-punches')->assertStatus(401);
    }

    public function test_clock_in_opens_a_shift_for_caller(): void
    {
        $company = $this->makeCompany();
        $user = $this->makeUserForCompany($company);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/time-punches/clock-in');
        $response->assertStatus(201);
        $response->assertJsonPath('data.is_open', true);
        $response->assertJsonPath('data.source', 'self');
        $this->assertNotNull($response->json('data.punched_in_at'));
        $this->assertNull($response->json('data.punched_out_at'));
    }

    public function test_double_clock_in_returns_conflict(): void
    {
        $company = $this->makeCompany();
        $user = $this->makeUserForCompany($company);

        Sanctum::actingAs($user);

        $this->postJson('/api/time-punches/clock-in')->assertStatus(201);
        $this->postJson('/api/time-punches/clock-in')->assertStatus(409);
    }

    public function test_clock_out_closes_shift_and_records_minutes_worked(): void
    {
        $company = $this->makeCompany();
        $user = $this->makeUserForCompany($company);
        $row = TimePunch::query()->create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'punched_in_at' => now()->subMinutes(90),
            'source' => 'self',
            'created_by_user_id' => $user->id,
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/time-punches/{$row->id}/clock-out");
        $response->assertOk();
        $response->assertJsonPath('data.is_open', false);
        $this->assertGreaterThanOrEqual(89, (int) $response->json('data.minutes_worked'));
        $this->assertLessThanOrEqual(91, (int) $response->json('data.minutes_worked'));
    }

    public function test_clock_out_on_already_closed_shift_returns_conflict(): void
    {
        $company = $this->makeCompany();
        $user = $this->makeUserForCompany($company);
        $row = TimePunch::query()->create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'punched_in_at' => now()->subHour(),
            'punched_out_at' => now(),
            'minutes_worked' => 60,
            'source' => 'self',
            'created_by_user_id' => $user->id,
        ]);

        Sanctum::actingAs($user);

        $this->postJson("/api/time-punches/{$row->id}/clock-out")->assertStatus(409);
    }

    public function test_clock_out_is_forbidden_for_user_outside_company(): void
    {
        $companyA = $this->makeCompany();
        $companyB = $this->makeCompany();
        $userA = $this->makeUserForCompany($companyA);
        $userB = $this->makeUserForCompany($companyB);

        $row = TimePunch::query()->create([
            'company_id' => $companyA->id,
            'user_id' => $userA->id,
            'punched_in_at' => now()->subHour(),
            'source' => 'self',
            'created_by_user_id' => $userA->id,
        ]);

        Sanctum::actingAs($userB);

        $this->postJson("/api/time-punches/{$row->id}/clock-out")->assertStatus(403);
    }

    public function test_index_is_company_scoped_for_regular_users(): void
    {
        $companyA = $this->makeCompany();
        $companyB = $this->makeCompany();
        $userA = $this->makeUserForCompany($companyA);
        $userB = $this->makeUserForCompany($companyB);

        TimePunch::query()->create([
            'company_id' => $companyA->id,
            'user_id' => $userA->id,
            'punched_in_at' => now()->subDay(),
            'punched_out_at' => now()->subDay()->addHours(8),
            'minutes_worked' => 480,
            'source' => 'self',
        ]);
        TimePunch::query()->create([
            'company_id' => $companyB->id,
            'user_id' => $userB->id,
            'punched_in_at' => now()->subDay(),
            'punched_out_at' => now()->subDay()->addHours(8),
            'minutes_worked' => 480,
            'source' => 'self',
        ]);

        Sanctum::actingAs($userA);

        $response = $this->getJson('/api/time-punches');
        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertEquals($companyA->id, $response->json('data.0.company_id'));
    }

    public function test_index_open_filter_returns_only_unclosed_shifts(): void
    {
        $company = $this->makeCompany();
        $user = $this->makeUserForCompany($company);

        TimePunch::query()->create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'punched_in_at' => now()->subHours(2),
            'punched_out_at' => now()->subHour(),
            'minutes_worked' => 60,
            'source' => 'self',
        ]);
        TimePunch::query()->create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'punched_in_at' => now()->subMinutes(30),
            'source' => 'self',
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/time-punches?open=1');
        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertTrue($response->json('data.0.is_open'));
    }

    public function test_manager_store_requires_out_after_in(): void
    {
        $company = $this->makeCompany();
        $manager = $this->makeUserForCompany($company);
        $employee = $this->makeUser();

        Sanctum::actingAs($manager);

        $this->postJson('/api/time-punches', [
            'user_id' => $employee->id,
            'punched_in_at' => '2026-05-20 09:00:00',
            'punched_out_at' => '2026-05-20 08:00:00',
        ])->assertStatus(422)->assertJsonValidationErrors(['punched_out_at']);
    }

    public function test_manager_store_computes_minutes_worked(): void
    {
        $company = $this->makeCompany();
        $manager = $this->makeUserForCompany($company);
        $employee = $this->makeUser();

        Sanctum::actingAs($manager);

        $response = $this->postJson('/api/time-punches', [
            'user_id' => $employee->id,
            'punched_in_at' => '2026-05-20 09:00:00',
            'punched_out_at' => '2026-05-20 17:30:00',
            'notes' => 'Manual entry — VPN logs',
        ]);
        $response->assertStatus(201);
        $this->assertEquals(510, $response->json('data.minutes_worked'));
        $this->assertSame('manager', $response->json('data.source'));
    }

    private function makeCompany(): Company
    {
        return Company::query()->create([
            'name' => 'TimePunch '.str()->uuid(),
            'type' => 'operator',
            'status' => 'active',
        ]);
    }

    private function makeUser(): User
    {
        return User::query()->create([
            'name' => 'TimePunch Test',
            'email' => 'tp-'.str()->uuid().'@example.test',
            'password' => bcrypt('password'),
            'status' => User::STATUS_ACTIVE,
        ]);
    }

    private function makeUserForCompany(Company $company): User
    {
        $user = $this->makeUser();
        $role = Role::query()->firstOrCreate(['name' => 'company_admin']);
        $user->companies()->attach($company->id, ['role_id' => $role->id]);

        return $user;
    }
}
