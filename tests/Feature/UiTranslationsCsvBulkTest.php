<?php

namespace Tests\Feature;

use App\Models\UiTranslation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class UiTranslationsCsvBulkTest extends TestCase
{
    use RefreshDatabase;

    public function test_export_and_import_round_trip_for_ru(): void
    {
        UiTranslation::query()->create([
            'language_code' => 'en',
            'key' => 'test.key.one',
            'value' => 'Hello',
        ]);
        UiTranslation::query()->create([
            'language_code' => 'en',
            'key' => 'test.key.two',
            'value' => 'World',
        ]);

        $exportPath = storage_path('app/testing_ui_export.csv');
        if (File::exists($exportPath)) {
            File::delete($exportPath);
        }

        $exit = Artisan::call('localization:export-ui-csv', ['--output' => $exportPath]);
        $this->assertSame(0, $exit);
        $this->assertTrue(File::exists($exportPath));

        $csv = File::get($exportPath);
        $this->assertStringContainsString('key,en,ru,hy', $csv);
        $this->assertStringContainsString('test.key.one', $csv);

        $importPath = storage_path('app/testing_ui_import.csv');
        $content = "key,en,ru,hy\n";
        $content .= "test.key.one,Hello,Привет,\n";
        $content .= "test.key.two,World,,\n";
        File::put($importPath, $content);

        $exit2 = Artisan::call('localization:import-ui-csv', [
            'path' => $importPath,
            '--lang' => 'ru',
        ]);
        $this->assertSame(0, $exit2);

        $this->assertSame(
            'Привет',
            UiTranslation::query()
                ->where('language_code', 'ru')
                ->where('key', 'test.key.one')
                ->value('value')
        );
        $this->assertNull(
            UiTranslation::query()
                ->where('language_code', 'ru')
                ->where('key', 'test.key.two')
                ->value('value')
        );

        File::delete($exportPath);
        File::delete($importPath);
    }

    public function test_import_rejects_default_language_without_force(): void
    {
        UiTranslation::query()->create([
            'language_code' => 'en',
            'key' => 'test.key.one',
            'value' => 'Hello',
        ]);

        $importPath = storage_path('app/testing_ui_import_en.csv');
        File::put($importPath, "key,en,ru,hy\ntest.key.one,New,\n");

        $exit = Artisan::call('localization:import-ui-csv', [
            'path' => $importPath,
            '--lang' => 'en',
        ]);
        $this->assertSame(1, $exit);

        $this->assertSame(
            'Hello',
            UiTranslation::query()
                ->where('language_code', 'en')
                ->where('key', 'test.key.one')
                ->value('value')
        );

        File::delete($importPath);
    }
}
