<?php

namespace Tests\Feature\OpenApi;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class GenerateOpenApiSpecTest extends TestCase
{
    use RefreshDatabase;

    public function test_generates_valid_openapi_3_json_with_paths(): void
    {
        $output = storage_path('app/test-openapi.json');
        if (File::exists($output)) {
            File::delete($output);
        }

        $this->artisan('api:generate-openapi', [
            '--output' => 'storage/app/test-openapi.json',
            '--prefix' => 'api',
            '--format' => 'json',
        ])->assertExitCode(0);

        $this->assertTrue(File::exists($output));
        $spec = json_decode(File::get($output), true);

        $this->assertSame('3.0.3', $spec['openapi']);
        $this->assertSame('ZULU Platform API', $spec['info']['title']);
        $this->assertNotEmpty($spec['paths']);
        $this->assertNotEmpty($spec['tags']);
        $this->assertArrayHasKey('bearerAuth', $spec['components']['securitySchemes']);

        // Spot-check a known endpoint
        $this->assertArrayHasKey('/api/login', $spec['paths']);

        File::delete($output);
    }

    public function test_yaml_format_writes_yaml_file(): void
    {
        $output = storage_path('app/test-openapi.yaml');
        if (File::exists($output)) {
            File::delete($output);
        }

        $this->artisan('api:generate-openapi', [
            '--output' => 'storage/app/test-openapi.json',
            '--format' => 'yaml',
        ])->assertExitCode(0);

        $this->assertTrue(File::exists($output));
        $content = File::get($output);
        $this->assertStringStartsWith('openapi:', $content);
        $this->assertStringContainsString('ZULU Platform API', $content);

        File::delete($output);
    }
}
