<?php

namespace Tests\Feature;

use App\Models\UiTranslation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class LocalizationUiConsistencyCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_consistency_check_passes_when_json_and_db_match(): void
    {
        $jsonPath = storage_path('app/testing_ui_defaults_match.json');
        File::put($jsonPath, json_encode([
            'test.guardrail.one' => 'One',
            'test.guardrail.two' => 'Two',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        UiTranslation::query()->create([
            'language_code' => 'en',
            'key' => 'test.guardrail.one',
            'value' => 'One',
        ]);
        UiTranslation::query()->create([
            'language_code' => 'en',
            'key' => 'test.guardrail.two',
            'value' => 'Two',
        ]);

        $this->artisan('localization:check-ui-consistency', [
            '--path' => $jsonPath,
            '--lang' => 'en',
        ])
            ->expectsOutputToContain('Missing in DB: 0')
            ->expectsOutputToContain('Extra in DB: 0')
            ->assertExitCode(0);

        File::delete($jsonPath);
    }

    public function test_consistency_check_fails_when_db_is_missing_canonical_keys(): void
    {
        $jsonPath = storage_path('app/testing_ui_defaults_missing.json');
        File::put($jsonPath, json_encode([
            'test.guardrail.one' => 'One',
            'test.guardrail.two' => 'Two',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        UiTranslation::query()->create([
            'language_code' => 'en',
            'key' => 'test.guardrail.one',
            'value' => 'One',
        ]);

        $this->artisan('localization:check-ui-consistency', [
            '--path' => $jsonPath,
            '--lang' => 'en',
        ])
            ->expectsOutputToContain('Missing in DB: 1')
            ->expectsOutputToContain('Consistency check failed: canonical keys are missing in DB.')
            ->assertExitCode(1);

        File::delete($jsonPath);
    }
}
