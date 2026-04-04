<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ImportSession;
use App\Models\User;
use Database\Seeders\RbacBootstrapSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class ImportUploadApiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, string>
     */
    private function authHeaders(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    public function test_successful_upload_creates_session(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $user = User::query()->where('email', 'admin@zulu.local')->firstOrFail();
        $company = Company::query()->where('name', 'ZULU Test Agency')->firstOrFail();

        $file = UploadedFile::fake()->create('catalog.csv', 50, 'text/csv');

        $response = $this->withHeaders($this->authHeaders($user))
            ->post('/api/import/upload', [
                'company_id' => $company->id,
                'template_version' => 'tpl-v1',
                'file' => $file,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', ImportSession::STATUS_UPLOADED)
            ->assertJsonPath('data.template_version', 'tpl-v1')
            ->assertJsonPath('data.dry_run', false)
            ->assertJsonPath('data.sync_mode', ImportSession::SYNC_MODE_PARTIAL)
            ->assertJsonPath('data.original_filename', 'catalog.csv');

        $sessionId = (int) $response->json('data.session_id');
        $this->assertDatabaseHas('import_sessions', [
            'id' => $sessionId,
            'company_id' => $company->id,
            'user_id' => $user->id,
            'template_version' => 'tpl-v1',
            'status' => ImportSession::STATUS_UPLOADED,
            'dry_run' => false,
            'sync_mode' => ImportSession::SYNC_MODE_PARTIAL,
        ]);
    }

    public function test_invalid_file_type_rejected(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $user = User::query()->where('email', 'admin@zulu.local')->firstOrFail();
        $company = Company::query()->where('name', 'ZULU Test Agency')->firstOrFail();

        $file = UploadedFile::fake()->create('notes.pdf', 100, 'application/pdf');

        $response = $this->withHeaders($this->authHeaders($user))
            ->post('/api/import/upload', [
                'company_id' => $company->id,
                'template_version' => 'tpl-v1',
                'file' => $file,
            ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('import_sessions', 0);
    }

    public function test_missing_template_version_rejected(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $user = User::query()->where('email', 'admin@zulu.local')->firstOrFail();
        $company = Company::query()->where('name', 'ZULU Test Agency')->firstOrFail();

        $file = UploadedFile::fake()->create('data.csv', 20, 'text/csv');

        $response = $this->withHeaders($this->authHeaders($user))
            ->post('/api/import/upload', [
                'company_id' => $company->id,
                'file' => $file,
            ]);

        $response->assertStatus(422);
    }

    public function test_dry_run_persists_correctly(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $user = User::query()->where('email', 'admin@zulu.local')->firstOrFail();
        $company = Company::query()->where('name', 'ZULU Test Agency')->firstOrFail();

        $file = UploadedFile::fake()->create('dry.csv', 20, 'text/csv');

        $response = $this->withHeaders($this->authHeaders($user))
            ->post('/api/import/upload', [
                'company_id' => $company->id,
                'template_version' => 'tpl-v1',
                'dry_run' => true,
                'file' => $file,
            ]);

        $response->assertStatus(201)->assertJsonPath('data.dry_run', true);

        $this->assertDatabaseHas('import_sessions', [
            'id' => $response->json('data.session_id'),
            'dry_run' => true,
        ]);
    }

    public function test_sync_mode_accepts_partial_and_full_and_rejects_invalid(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $user = User::query()->where('email', 'admin@zulu.local')->firstOrFail();
        $company = Company::query()->where('name', 'ZULU Test Agency')->firstOrFail();

        $filePartial = UploadedFile::fake()->create('p.csv', 10, 'text/csv');
        $r1 = $this->withHeaders($this->authHeaders($user))
            ->post('/api/import/upload', [
                'company_id' => $company->id,
                'template_version' => 'tpl-v1',
                'sync_mode' => 'partial',
                'file' => $filePartial,
            ]);
        $r1->assertStatus(201)->assertJsonPath('data.sync_mode', 'partial');

        $fileFull = UploadedFile::fake()->create('f.xlsx', 10, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $r2 = $this->withHeaders($this->authHeaders($user))
            ->post('/api/import/upload', [
                'company_id' => $company->id,
                'template_version' => 'tpl-v1',
                'sync_mode' => 'full',
                'file' => $fileFull,
            ]);
        $r2->assertStatus(201)->assertJsonPath('data.sync_mode', 'full');

        $fileBad = UploadedFile::fake()->create('bad.csv', 10, 'text/csv');
        $r3 = $this->withHeaders($this->authHeaders($user))
            ->post('/api/import/upload', [
                'company_id' => $company->id,
                'template_version' => 'tpl-v1',
                'sync_mode' => 'nope',
                'file' => $fileBad,
            ]);
        $r3->assertStatus(422);
    }
}
