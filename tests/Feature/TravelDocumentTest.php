<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** B2C customer travel-documents CRUD (account "Travel documents" section). */
class TravelDocumentTest extends TestCase
{
    use RefreshDatabase;

    public function test_crud_validation_and_ownership(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        // Unknown document type is rejected.
        $this->postJson('/api/account/documents', ['type' => 'banana'])->assertStatus(422);

        $id = $this->postJson('/api/account/documents', [
            'type' => 'passport', 'number' => 'AB1234567', 'issuing_country' => 'Armenia', 'expiry_date' => '2030-01-01',
        ])->assertCreated()->assertJsonPath('data.type', 'passport')->json('data.id');

        $this->postJson('/api/account/documents', [
            'type' => 'loyalty', 'label' => 'Aeroflot Bonus', 'number' => 'FF-99',
        ])->assertCreated();

        $this->getJson('/api/account/documents')->assertOk()->assertJsonCount(2, 'data');

        $this->putJson("/api/account/documents/{$id}", ['type' => 'passport', 'number' => 'XYZ'])
            ->assertOk()->assertJsonPath('data.number', 'XYZ');

        // Another user cannot touch this customer's document.
        Sanctum::actingAs($other, ['*']);
        $this->putJson("/api/account/documents/{$id}", ['type' => 'passport'])->assertStatus(404);
        $this->deleteJson("/api/account/documents/{$id}")->assertStatus(404);

        Sanctum::actingAs($user, ['*']);
        $this->deleteJson("/api/account/documents/{$id}")->assertOk();
        $this->getJson('/api/account/documents')->assertJsonCount(1, 'data');
    }
}
