<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ImportSession;
use App\Models\ImportStagingRow;
use App\Models\User;
use Database\Seeders\RbacBootstrapSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class ImportStageApiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, string>
     */
    private function authHeaders(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    public function test_csv_upload_and_stage_success(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $user = User::query()->where('email', 'admin@zulu.local')->firstOrFail();
        $company = Company::query()->where('name', 'ZULU Test Agency')->firstOrFail();

        $csv = "offer_external_key,title\nO1,Beach Trip\nO2,City Break\n";
        $file = UploadedFile::fake()->createWithContent('offers.csv', $csv);

        $upload = $this->withHeaders($this->authHeaders($user))
            ->post('/api/import/upload', [
                'company_id' => $company->id,
                'template_version' => 'offers',
                'file' => $file,
            ]);
        $upload->assertStatus(201);
        $sessionId = (int) $upload->json('data.session_id');

        $stage = $this->withHeaders($this->authHeaders($user))
            ->post('/api/import/'.$sessionId.'/stage');
        $stage->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', ImportSession::STATUS_STAGED)
            ->assertJsonPath('data.rows_total', 2)
            ->assertJsonPath('data.rows_valid', 2)
            ->assertJsonPath('data.rows_invalid', 0);

        $this->assertDatabaseCount('import_staging_rows', 2);
        $this->assertDatabaseHas('import_sessions', [
            'id' => $sessionId,
            'status' => ImportSession::STATUS_STAGED,
            'rows_total' => 2,
            'rows_valid' => 2,
            'rows_invalid' => 0,
            'validation_errors_count' => 0,
        ]);
    }

    public function test_xlsx_upload_and_stage_success(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $user = User::query()->where('email', 'admin@zulu.local')->firstOrFail();
        $company = Company::query()->where('name', 'ZULU Test Agency')->firstOrFail();

        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'zulu_stage_'.uniqid('', true).'.xlsx';
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Offers');
        $sheet->setCellValue('A1', 'Offer External Key');
        $sheet->setCellValue('B1', 'Title');
        $sheet->setCellValue('A2', 'X1');
        $sheet->setCellValue('B2', 'One');
        (new Xlsx($spreadsheet))->save($path);

        $file = new UploadedFile(
            $path,
            'offers.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true
        );

        $upload = $this->withHeaders($this->authHeaders($user))
            ->post('/api/import/upload', [
                'company_id' => $company->id,
                'template_version' => 'offers',
                'file' => $file,
            ]);
        $upload->assertStatus(201);
        $sessionId = (int) $upload->json('data.session_id');
        @unlink($path);

        $stage = $this->withHeaders($this->authHeaders($user))
            ->post('/api/import/'.$sessionId.'/stage');
        $stage->assertStatus(200)
            ->assertJsonPath('data.rows_total', 1)
            ->assertJsonPath('data.rows_valid', 1);

        $this->assertDatabaseHas('import_staging_rows', [
            'import_session_id' => $sessionId,
            'entity_type' => 'offers',
            'validation_status' => ImportStagingRow::VALIDATION_OK,
            'sheet_name' => 'Offers',
            'row_number' => 2,
        ]);
    }

    public function test_missing_required_headers_returns_validation_failed(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $user = User::query()->where('email', 'admin@zulu.local')->firstOrFail();
        $company = Company::query()->where('name', 'ZULU Test Agency')->firstOrFail();

        $csv = "title\nOnly Title\n";
        $file = UploadedFile::fake()->createWithContent('bad.csv', $csv);

        $upload = $this->withHeaders($this->authHeaders($user))
            ->post('/api/import/upload', [
                'company_id' => $company->id,
                'template_version' => 'offers',
                'file' => $file,
            ]);
        $sessionId = (int) $upload->json('data.session_id');

        $stage = $this->withHeaders($this->authHeaders($user))
            ->post('/api/import/'.$sessionId.'/stage');
        $stage->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('data.status', ImportSession::STATUS_VALIDATION_FAILED);

        $this->assertDatabaseCount('import_staging_rows', 0);
        $this->assertDatabaseHas('import_sessions', [
            'id' => $sessionId,
            'status' => ImportSession::STATUS_VALIDATION_FAILED,
            'rows_total' => 0,
        ]);
    }

    public function test_invalid_rows_staged_with_validation_status_invalid(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $user = User::query()->where('email', 'admin@zulu.local')->firstOrFail();
        $company = Company::query()->where('name', 'ZULU Test Agency')->firstOrFail();

        $csv = "offer_external_key,title\nO1,Ok\n,Bad\nO3,Fine\n";
        $file = UploadedFile::fake()->createWithContent('mixed.csv', $csv);

        $upload = $this->withHeaders($this->authHeaders($user))
            ->post('/api/import/upload', [
                'company_id' => $company->id,
                'template_version' => 'offers',
                'file' => $file,
            ]);
        $sessionId = (int) $upload->json('data.session_id');

        $stage = $this->withHeaders($this->authHeaders($user))
            ->post('/api/import/'.$sessionId.'/stage');
        $stage->assertStatus(200)
            ->assertJsonPath('data.rows_total', 3)
            ->assertJsonPath('data.rows_valid', 2)
            ->assertJsonPath('data.rows_invalid', 1);

        $this->assertDatabaseHas('import_staging_rows', [
            'import_session_id' => $sessionId,
            'external_key' => 'O1',
            'validation_status' => ImportStagingRow::VALIDATION_OK,
        ]);
        $this->assertDatabaseHas('import_staging_rows', [
            'import_session_id' => $sessionId,
            'external_key' => null,
            'validation_status' => ImportStagingRow::VALIDATION_INVALID,
            'row_number' => 3,
        ]);

        $this->assertDatabaseHas('import_sessions', [
            'id' => $sessionId,
            'validation_errors_count' => 1,
        ]);
    }

    public function test_counters_update_correctly_for_parent_template(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $user = User::query()->where('email', 'admin@zulu.local')->firstOrFail();
        $company = Company::query()->where('name', 'ZULU Test Agency')->firstOrFail();

        $csv = "cabin_external_key,flight_external_key\nC1,F1\n, F2\nC3,\n";
        $file = UploadedFile::fake()->createWithContent('cabins.csv', $csv);

        $upload = $this->withHeaders($this->authHeaders($user))
            ->post('/api/import/upload', [
                'company_id' => $company->id,
                'template_version' => 'flight_cabins',
                'file' => $file,
            ]);
        $sessionId = (int) $upload->json('data.session_id');

        $stage = $this->withHeaders($this->authHeaders($user))
            ->post('/api/import/'.$sessionId.'/stage');
        $stage->assertStatus(200)
            ->assertJsonPath('data.rows_valid', 1)
            ->assertJsonPath('data.rows_invalid', 2)
            ->assertJsonPath('data.rows_total', 3);

        $this->assertDatabaseHas('import_sessions', [
            'id' => $sessionId,
            'validation_errors_count' => 2,
        ]);
    }

    public function test_get_import_session_returns_metadata(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $user = User::query()->where('email', 'admin@zulu.local')->firstOrFail();
        $company = Company::query()->where('name', 'ZULU Test Agency')->firstOrFail();

        $csv = "offer_external_key\nO1\n";
        $file = UploadedFile::fake()->createWithContent('one.csv', $csv);

        $upload = $this->withHeaders($this->authHeaders($user))
            ->post('/api/import/upload', [
                'company_id' => $company->id,
                'template_version' => 'offers',
                'file' => $file,
            ]);
        $sessionId = (int) $upload->json('data.session_id');

        $this->withHeaders($this->authHeaders($user))
            ->post('/api/import/'.$sessionId.'/stage')
            ->assertStatus(200);

        $show = $this->withHeaders($this->authHeaders($user))
            ->get('/api/import/'.$sessionId);
        $show->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.session_id', $sessionId)
            ->assertJsonPath('data.status', ImportSession::STATUS_STAGED)
            ->assertJsonPath('data.rows_total', 1)
            ->assertJsonPath('data.template_version', 'offers');
    }

    public function test_stage_twice_after_staged_returns_422(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $user = User::query()->where('email', 'admin@zulu.local')->firstOrFail();
        $company = Company::query()->where('name', 'ZULU Test Agency')->firstOrFail();

        $csv = "offer_external_key\nO1\n";
        $file = UploadedFile::fake()->createWithContent('one.csv', $csv);

        $upload = $this->withHeaders($this->authHeaders($user))
            ->post('/api/import/upload', [
                'company_id' => $company->id,
                'template_version' => 'offers',
                'file' => $file,
            ]);
        $sessionId = (int) $upload->json('data.session_id');

        $this->withHeaders($this->authHeaders($user))
            ->post('/api/import/'.$sessionId.'/stage')
            ->assertStatus(200);

        $again = $this->withHeaders($this->authHeaders($user))
            ->post('/api/import/'.$sessionId.'/stage');
        $again->assertStatus(422);
    }
}
