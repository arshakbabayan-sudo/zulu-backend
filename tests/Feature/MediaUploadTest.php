<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MediaUploadTest extends TestCase
{
    use RefreshDatabase;

    public function test_rejects_unauthenticated_request(): void
    {
        $response = $this->postJson('/api/media/upload', [
            'section' => 'hotels',
        ]);

        $response->assertStatus(401);
    }

    public function test_uploads_image_to_section_folder_and_returns_url(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/media/upload', [
            'section' => 'hotels',
            'file' => UploadedFile::fake()->image('photo.jpg', 1200, 800),
        ]);

        $response->assertOk();
        $response->assertJsonStructure([
            'success',
            'data' => ['url', 'path', 'size_bytes', 'mime'],
        ]);

        $path = $response->json('data.path');
        $this->assertStringStartsWith('uploads/hotels/', $path);
        $this->assertStringEndsWith('.jpg', $path);

        Storage::disk('public')->assertExists($path);

        $url = (string) $response->json('data.url');
        $this->assertStringContainsString('/storage/uploads/hotels/', $url);
    }

    public function test_rejects_unknown_section(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/media/upload', [
            'section' => 'malicious_path',
            'file' => UploadedFile::fake()->image('photo.jpg'),
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('section');
    }

    public function test_rejects_non_image_file(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/media/upload', [
            'section' => 'hotels',
            'file' => UploadedFile::fake()->create('virus.exe', 100, 'application/x-msdownload'),
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('file');
    }

    public function test_rejects_oversize_file(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        // Laravel's UploadedFile::fake()->image size in KB; cap is 10240.
        $response = $this->postJson('/api/media/upload', [
            'section' => 'hotels',
            'file' => UploadedFile::fake()->image('huge.jpg')->size(11000),
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('file');
    }

    public function test_each_section_goes_to_its_own_folder(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $sections = ['hotels', 'flights', 'cars', 'transfers', 'excursions', 'visas', 'packages', 'banners'];
        foreach ($sections as $section) {
            $response = $this->postJson('/api/media/upload', [
                'section' => $section,
                'file' => UploadedFile::fake()->image("{$section}.jpg"),
            ]);

            $response->assertOk();
            $this->assertStringStartsWith("uploads/{$section}/", $response->json('data.path'), "Section {$section} should land in its own folder");
        }
    }
}
