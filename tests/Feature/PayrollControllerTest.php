<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\PayrollRecord;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 7.15 feature tests — payroll endpoints.
 *
 * Routes:
 *   GET   /api/payroll
 *   POST  /api/payroll
 *   PATCH /api/payroll/{id}/status
 *
 * Critical behavior locked down:
 *   - gross_pay = base_salary + hourly_rate × hours_worked + commission + bonus
 *   - net_pay = max(0, gross_pay - deductions_amount)
 *   - status transitions to "paid" stamp paid_at; backing off clears it
 *   - period_end must be after_or_equal to period_start
 *   - Index is company-scoped for non-super-admins
 */
class PayrollControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_caller_is_rejected(): void
    {
        $this->getJson('/api/payroll')->assertStatus(401);
    }

    public function test_index_is_company_scoped_for_regular_users(): void
    {
        $companyA = $this->makeCompany();
        $companyB = $this->makeCompany();
        $userA = $this->makeUserForCompany($companyA);
        // Two different employees because (user_id, period_start, period_end)
        // is uniquely indexed at the DB level.
        $employeeA = $this->makeUser();
        $employeeB = $this->makeUser();

        PayrollRecord::query()->create([
            'company_id' => $companyA->id,
            'user_id' => $employeeA->id,
            'period_start' => '2026-05-01',
            'period_end' => '2026-05-31',
            'base_salary' => 2000,
            'gross_pay' => 2000,
            'net_pay' => 2000,
            'currency' => 'USD',
            'status' => 'draft',
        ]);
        PayrollRecord::query()->create([
            'company_id' => $companyB->id,
            'user_id' => $employeeB->id,
            'period_start' => '2026-05-01',
            'period_end' => '2026-05-31',
            'base_salary' => 3000,
            'gross_pay' => 3000,
            'net_pay' => 3000,
            'currency' => 'USD',
            'status' => 'draft',
        ]);

        Sanctum::actingAs($userA);

        $response = $this->getJson('/api/payroll');
        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertEquals($companyA->id, $response->json('data.0.company_id'));
    }

    public function test_index_status_filter_narrows_results(): void
    {
        $company = $this->makeCompany();
        $user = $this->makeUserForCompany($company);

        PayrollRecord::query()->create([
            'company_id' => $company->id,
            'user_id' => $this->makeUser()->id,
            'period_start' => '2026-04-01',
            'period_end' => '2026-04-30',
            'base_salary' => 1500,
            'gross_pay' => 1500,
            'net_pay' => 1500,
            'currency' => 'USD',
            'status' => 'paid',
        ]);
        PayrollRecord::query()->create([
            'company_id' => $company->id,
            'user_id' => $this->makeUser()->id,
            'period_start' => '2026-05-01',
            'period_end' => '2026-05-31',
            'base_salary' => 1500,
            'gross_pay' => 1500,
            'net_pay' => 1500,
            'currency' => 'USD',
            'status' => 'draft',
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/payroll?status=paid');
        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('paid', $response->json('data.0.status'));
    }

    public function test_store_computes_gross_and_net_pay_from_components(): void
    {
        $company = $this->makeCompany();
        $employer = $this->makeUserForCompany($company);
        $employee = $this->makeUser();

        Sanctum::actingAs($employer);

        $response = $this->postJson('/api/payroll', [
            'user_id' => $employee->id,
            'period_start' => '2026-05-01',
            'period_end' => '2026-05-31',
            'base_salary' => 1500,
            'hourly_rate' => 20,
            'hours_worked' => 10,
            'commission_amount' => 200,
            'bonus_amount' => 100,
            'deductions_amount' => 250,
            'currency' => 'usd',
        ]);

        $response->assertStatus(201);
        // gross = 1500 + 20*10 + 200 + 100 = 2000
        // net   = max(0, 2000 - 250) = 1750
        $this->assertEquals(2000.0, $response->json('data.gross_pay'));
        $this->assertEquals(1750.0, $response->json('data.net_pay'));
        $response->assertJsonPath('data.currency', 'USD');
        $response->assertJsonPath('data.status', 'draft');
    }

    public function test_store_floors_net_pay_at_zero_when_deductions_exceed_gross(): void
    {
        $company = $this->makeCompany();
        $employer = $this->makeUserForCompany($company);
        $employee = $this->makeUser();

        Sanctum::actingAs($employer);

        $response = $this->postJson('/api/payroll', [
            'user_id' => $employee->id,
            'period_start' => '2026-05-01',
            'period_end' => '2026-05-31',
            'base_salary' => 500,
            'deductions_amount' => 800,
        ]);

        $response->assertStatus(201);
        $this->assertEquals(500.0, $response->json('data.gross_pay'));
        $this->assertEquals(0.0, $response->json('data.net_pay'));
    }

    public function test_store_rejects_inverted_period(): void
    {
        $company = $this->makeCompany();
        Sanctum::actingAs($this->makeUserForCompany($company));

        $this->postJson('/api/payroll', [
            'user_id' => $this->makeUser()->id,
            'period_start' => '2026-05-31',
            'period_end' => '2026-05-01',
            'base_salary' => 1000,
        ])->assertStatus(422)->assertJsonValidationErrors(['period_end']);
    }

    public function test_change_status_to_paid_stamps_paid_at(): void
    {
        $company = $this->makeCompany();
        $user = $this->makeUserForCompany($company);
        $row = PayrollRecord::query()->create([
            'company_id' => $company->id,
            'user_id' => $this->makeUser()->id,
            'period_start' => '2026-05-01',
            'period_end' => '2026-05-31',
            'base_salary' => 1500,
            'gross_pay' => 1500,
            'net_pay' => 1500,
            'currency' => 'USD',
            'status' => 'finalized',
        ]);

        Sanctum::actingAs($user);

        $response = $this->patchJson("/api/payroll/{$row->id}/status", ['status' => 'paid']);

        $response->assertOk();
        $response->assertJsonPath('data.status', 'paid');
        $this->assertNotNull($response->json('data.paid_at'));
    }

    public function test_change_status_back_from_paid_clears_paid_at(): void
    {
        $company = $this->makeCompany();
        $user = $this->makeUserForCompany($company);
        $row = PayrollRecord::query()->create([
            'company_id' => $company->id,
            'user_id' => $this->makeUser()->id,
            'period_start' => '2026-05-01',
            'period_end' => '2026-05-31',
            'base_salary' => 1500,
            'gross_pay' => 1500,
            'net_pay' => 1500,
            'currency' => 'USD',
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        Sanctum::actingAs($user);

        $response = $this->patchJson("/api/payroll/{$row->id}/status", ['status' => 'finalized']);

        $response->assertOk();
        $response->assertJsonPath('data.status', 'finalized');
        $this->assertNull($response->json('data.paid_at'));
    }

    public function test_payslip_pdf_streams_for_authorised_company_user(): void
    {
        $company = $this->makeCompany();
        $user = $this->makeUserForCompany($company);
        $row = PayrollRecord::query()->create([
            'company_id' => $company->id,
            'user_id' => $this->makeUser()->id,
            'period_start' => '2026-05-01',
            'period_end' => '2026-05-31',
            'base_salary' => 1500,
            'commission_amount' => 200,
            'gross_pay' => 1700,
            'net_pay' => 1700,
            'currency' => 'USD',
            'status' => 'finalized',
        ]);

        Sanctum::actingAs($user);

        $response = $this->get("/api/payroll/{$row->id}/payslip");

        $response->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF-', (string) $response->getContent());
    }

    public function test_payslip_is_forbidden_for_user_outside_company(): void
    {
        $companyA = $this->makeCompany();
        $companyB = $this->makeCompany();
        $userB = $this->makeUserForCompany($companyB);

        $row = PayrollRecord::query()->create([
            'company_id' => $companyA->id,
            'user_id' => $this->makeUser()->id,
            'period_start' => '2026-05-01',
            'period_end' => '2026-05-31',
            'base_salary' => 1500,
            'gross_pay' => 1500,
            'net_pay' => 1500,
            'currency' => 'USD',
            'status' => 'finalized',
        ]);

        Sanctum::actingAs($userB);

        $this->get("/api/payroll/{$row->id}/payslip")->assertStatus(403);
    }

    public function test_change_status_is_forbidden_for_user_outside_company(): void
    {
        // Regression for I1 audit finding F-1 (2026-05-20): previously
        // PATCH /api/payroll/{id}/status had no scope check, so any
        // authenticated user — including a freshly-registered customer —
        // could mark any company's payroll row as paid by guessing the id.
        $companyA = $this->makeCompany();
        $companyB = $this->makeCompany();
        $userB = $this->makeUserForCompany($companyB);

        $row = PayrollRecord::query()->create([
            'company_id' => $companyA->id,
            'user_id' => $this->makeUser()->id,
            'period_start' => '2026-05-01',
            'period_end' => '2026-05-31',
            'base_salary' => 1500,
            'gross_pay' => 1500,
            'net_pay' => 1500,
            'currency' => 'USD',
            'status' => 'draft',
        ]);

        Sanctum::actingAs($userB);

        $this->patchJson("/api/payroll/{$row->id}/status", ['status' => 'paid'])
            ->assertStatus(403);

        $this->assertSame('draft', $row->fresh()->status);
        $this->assertNull($row->fresh()->paid_at);
    }

    public function test_bank_batch_csv_includes_only_company_finalized_records_by_default(): void
    {
        $companyA = $this->makeCompany();
        $companyB = $this->makeCompany();
        $userA = $this->makeUserForCompany($companyA);

        // In-scope (companyA + finalized) — should appear.
        $employee1 = $this->makeUser();
        PayrollRecord::query()->create([
            'company_id' => $companyA->id,
            'user_id' => $employee1->id,
            'period_start' => '2026-05-01',
            'period_end' => '2026-05-31',
            'base_salary' => 2000,
            'gross_pay' => 2000,
            'net_pay' => 1850,
            'deductions_amount' => 150,
            'currency' => 'USD',
            'status' => 'finalized',
        ]);

        // Out of scope — different company.
        $employee2 = $this->makeUser();
        PayrollRecord::query()->create([
            'company_id' => $companyB->id,
            'user_id' => $employee2->id,
            'period_start' => '2026-05-01',
            'period_end' => '2026-05-31',
            'base_salary' => 3000,
            'gross_pay' => 3000,
            'net_pay' => 3000,
            'currency' => 'USD',
            'status' => 'finalized',
        ]);

        // Out of scope — same company but draft (default filter is finalized).
        $employee3 = $this->makeUser();
        PayrollRecord::query()->create([
            'company_id' => $companyA->id,
            'user_id' => $employee3->id,
            'period_start' => '2026-04-01',
            'period_end' => '2026-04-30',
            'base_salary' => 1000,
            'gross_pay' => 1000,
            'net_pay' => 1000,
            'currency' => 'USD',
            'status' => 'draft',
        ]);

        Sanctum::actingAs($userA);

        $response = $this->get('/api/payroll/bank-batch');

        $response->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('Content-Type'));
        $csv = $response->streamedContent();
        $this->assertStringContainsString('payroll_id,employee_name', $csv);
        $this->assertStringContainsString($employee1->email, $csv);
        $this->assertStringNotContainsString($employee2->email, $csv);
        $this->assertStringNotContainsString($employee3->email, $csv);
        $this->assertStringContainsString('1850.00', $csv);
    }

    public function test_bank_batch_csv_status_filter_can_be_overridden(): void
    {
        $company = $this->makeCompany();
        $user = $this->makeUserForCompany($company);
        $employee = $this->makeUser();

        PayrollRecord::query()->create([
            'company_id' => $company->id,
            'user_id' => $employee->id,
            'period_start' => '2026-05-01',
            'period_end' => '2026-05-31',
            'base_salary' => 1000,
            'gross_pay' => 1000,
            'net_pay' => 1000,
            'currency' => 'USD',
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        Sanctum::actingAs($user);

        $response = $this->get('/api/payroll/bank-batch?status=paid');
        $response->assertOk();
        $csv = $response->streamedContent();
        $this->assertStringContainsString($employee->email, $csv);
    }

    public function test_bank_batch_rejects_unauthenticated_caller(): void
    {
        $this->get('/api/payroll/bank-batch')->assertStatus(401);
    }

    public function test_change_status_rejects_invalid_value(): void
    {
        $company = $this->makeCompany();
        $user = $this->makeUserForCompany($company);
        $row = PayrollRecord::query()->create([
            'company_id' => $company->id,
            'user_id' => $this->makeUser()->id,
            'period_start' => '2026-05-01',
            'period_end' => '2026-05-31',
            'base_salary' => 1500,
            'gross_pay' => 1500,
            'net_pay' => 1500,
            'currency' => 'USD',
            'status' => 'draft',
        ]);

        Sanctum::actingAs($user);

        $this->patchJson("/api/payroll/{$row->id}/status", ['status' => 'archived'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }

    private function makeCompany(): Company
    {
        return Company::query()->create([
            'name' => 'Phase 7.15 '.str()->uuid(),
            'type' => 'operator',
            'status' => 'active',
        ]);
    }

    private function makeUser(): User
    {
        return User::query()->create([
            'name' => 'Phase 7.15 Test',
            'email' => 'p715-'.str()->uuid().'@example.test',
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
