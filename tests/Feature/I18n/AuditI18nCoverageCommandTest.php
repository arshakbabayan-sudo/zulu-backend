<?php

namespace Tests\Feature\I18n;

use App\Models\SupportedLanguage;
use App\Models\UiTranslation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AuditI18nCoverageCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_reports_per_language_coverage(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'i18n_').'.json';
        File::put($tmp, json_encode(['app.title' => 'ZULU', 'home.welcome' => 'Welcome', 'cta.go' => 'Go']));

        SupportedLanguage::query()->updateOrCreate(['code' => 'en'], ['name' => 'English', 'name_en' => 'English', 'is_default' => true, 'is_enabled' => true]);
        SupportedLanguage::query()->updateOrCreate(['code' => 'hy'], ['name' => 'Armenian', 'name_en' => 'Armenian', 'is_default' => false, 'is_enabled' => true]);

        // English: all 3 keys present
        foreach (['app.title' => 'ZULU', 'home.welcome' => 'Welcome', 'cta.go' => 'Go'] as $k => $v) {
            UiTranslation::query()->create(['key' => $k, 'value' => $v, 'language_code' => 'en']);
        }
        // Armenian: only 1 key
        UiTranslation::query()->create(['key' => 'app.title', 'value' => 'ԶՈՒԼՈՒ', 'language_code' => 'hy']);

        $this->artisan('i18n:audit', ['--canonical' => $tmp])
            ->expectsOutputToContain('Canonical key count (en): 3')
            ->assertExitCode(0);

        @unlink($tmp);
    }

    public function test_audit_fails_when_canonical_path_invalid(): void
    {
        $this->artisan('i18n:audit', ['--canonical' => '/nonexistent/path.json'])
            ->expectsOutputToContain('Canonical defaults JSON not found')
            ->assertExitCode(1);
    }
}
