<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * GET /api/companies/{company}/google-drive/status
 *
 * Regression guard for the 2026-06-19 "SERVER ERROR" on the Integrations page:
 * the status check used to decrypt the stored refresh token, so a company left
 * with a token from a previous APP_KEY / a half-finished connect made the
 * endpoint 500. It must degrade to "not connected" instead.
 */
class GoogleDriveStatusTest extends TestCase
{
    use RefreshDatabase;

    private function makeCompany(): Company
    {
        return Company::query()->create([
            'name' => 'GDrive status '.str()->uuid(),
            'type' => 'operator',
        ]);
    }

    private function makeSuperAdmin(): User
    {
        $user = User::query()->create([
            'name' => 'GDrive status admin',
            'email' => 'gd-'.str()->uuid().'@example.test',
            'password' => bcrypt('password'),
            'status' => User::STATUS_ACTIVE,
        ]);
        $role = Role::query()->firstOrCreate(['name' => 'super_admin']);
        $platform = $this->makeCompany();
        $user->companies()->attach($platform->id, ['role_id' => $role->id]);
        $user->is_super_admin = true;

        return $user;
    }

    public function test_status_reports_not_connected_for_a_fresh_company(): void
    {
        $company = $this->makeCompany();
        Sanctum::actingAs($this->makeSuperAdmin());

        $this->getJson("/api/companies/{$company->id}/google-drive/status")
            ->assertOk()
            ->assertJsonPath('data.connected', false);
    }

    public function test_status_does_not_500_when_the_stored_token_cannot_be_decrypted(): void
    {
        $company = $this->makeCompany();

        // A refresh token the `encrypted` cast can no longer decrypt (e.g. written
        // under a previous APP_KEY), plus a root folder id → reads as connected.
        DB::table('companies')->where('id', $company->id)->update([
            'google_refresh_token' => 'totally-not-valid-ciphertext',
            'google_drive_folder_id' => 'folder_xyz',
        ]);

        Sanctum::actingAs($this->makeSuperAdmin());

        $this->getJson("/api/companies/{$company->id}/google-drive/status")
            ->assertOk()
            ->assertJsonPath('data.connected', true)
            ->assertJsonPath('data.folder_id', 'folder_xyz');
    }
}
