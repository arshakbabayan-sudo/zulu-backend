<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CaseAutoResponderTest extends TestCase
{
    use RefreshDatabase;

    public function test_opening_a_case_acknowledges_receipt_to_the_opener(): void
    {
        $user = User::factory()->create(['preferred_language' => 'hy']);
        Sanctum::actingAs($user);

        $this->postJson('/api/cases', [
            'title' => 'My booking question',
            'description' => 'I need help with my trip.',
        ])->assertStatus(201);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $user->id,
            'type' => 'case_received',
        ]);
    }

    public function test_acknowledgement_is_localized_to_the_openers_language(): void
    {
        $ru = User::factory()->create(['preferred_language' => 'ru']);
        Sanctum::actingAs($ru);

        $this->postJson('/api/cases', ['title' => 'T', 'description' => 'D'])->assertStatus(201);

        $note = \App\Models\Notification::query()
            ->where('user_id', $ru->id)
            ->where('type', 'case_received')
            ->firstOrFail();

        $this->assertStringContainsString('Запрос получен', (string) $note->title);
    }
}
