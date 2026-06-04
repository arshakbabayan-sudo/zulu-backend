<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** B2C customer saved-travelers CRUD (account "Travelers" section). */
class SavedTravelerTest extends TestCase
{
    use RefreshDatabase;

    public function test_crud_and_ownership(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $id = $this->postJson('/api/account/travelers', [
            'first_name' => 'Ani', 'last_name' => 'Babayan',
            'relationship' => 'self', 'is_self' => true,
            'passport_number' => 'AB1234567', 'passport_country' => 'Armenia',
        ])->assertCreated()->assertJsonPath('data.first_name', 'Ani')->json('data.id');

        $this->postJson('/api/account/travelers', [
            'first_name' => 'Kid', 'last_name' => 'Babayan', 'relationship' => 'child',
        ])->assertCreated();

        // List: self first, then by last name.
        $this->getJson('/api/account/travelers')
            ->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.is_self', true);

        $this->putJson("/api/account/travelers/{$id}", ['first_name' => 'Anie', 'last_name' => 'Babayan'])
            ->assertOk()->assertJsonPath('data.first_name', 'Anie');

        // Another user cannot touch this customer's traveler.
        Sanctum::actingAs($other, ['*']);
        $this->putJson("/api/account/travelers/{$id}", ['first_name' => 'X', 'last_name' => 'Y'])->assertStatus(404);
        $this->deleteJson("/api/account/travelers/{$id}")->assertStatus(404);

        // Owner deletes.
        Sanctum::actingAs($user, ['*']);
        $this->deleteJson("/api/account/travelers/{$id}")->assertOk();
        $this->getJson('/api/account/travelers')->assertJsonCount(1, 'data');
    }
}
