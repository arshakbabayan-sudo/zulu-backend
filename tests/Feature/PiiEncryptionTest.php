<?php

namespace Tests\Feature;

use App\Models\Passenger;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 1 (GDPR Critical) — verifies PII fields are encrypted at rest.
 *
 * Strategy: write a value through the Eloquent model (triggers the encrypted
 * cast on save), then read the raw DB column via DB::table() (bypassing the
 * cast) and assert it is NOT the plaintext value. Read back through the
 * model and assert plaintext round-trip works.
 */
class PiiEncryptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_passenger_passport_number_is_encrypted_at_rest(): void
    {
        $plaintext = 'AB1234567';

        $passenger = Passenger::query()->create([
            'first_name' => 'John',
            'last_name' => 'Doe',
            'passport_number' => $plaintext,
            'passenger_type' => 'adult',
        ]);

        // Raw DB read bypasses the cast — must NOT equal plaintext.
        $raw = DB::table('passengers')->where('id', $passenger->id)->value('passport_number');
        $this->assertNotSame($plaintext, $raw, 'passport_number was stored as plaintext');
        $this->assertNotEmpty($raw, 'passport_number is missing from DB');

        // Model read uses cast — must round-trip to plaintext.
        $fresh = Passenger::query()->find($passenger->id);
        $this->assertSame($plaintext, $fresh->passport_number, 'cast round-trip broken');
    }

    public function test_passenger_nationality_is_encrypted_at_rest(): void
    {
        $plaintext = 'AM';

        $passenger = Passenger::query()->create([
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'nationality' => $plaintext,
            'passenger_type' => 'adult',
        ]);

        $raw = DB::table('passengers')->where('id', $passenger->id)->value('nationality');
        $this->assertNotSame($plaintext, $raw);

        $fresh = Passenger::query()->find($passenger->id);
        $this->assertSame($plaintext, $fresh->nationality);
    }

    public function test_user_nationality_is_encrypted(): void
    {
        $plaintext = 'RU';

        $user = User::query()->create([
            'name' => 'Nat Test',
            'email' => 'enc-nat-'.str()->uuid().'@example.test',
            'password' => bcrypt('password'),
            'status' => User::STATUS_ACTIVE,
            'nationality' => $plaintext,
        ]);

        $raw = DB::table('users')->where('id', $user->id)->value('nationality');
        $this->assertNotSame($plaintext, $raw);

        $fresh = User::query()->find($user->id);
        $this->assertSame($plaintext, $fresh->nationality);
    }
}
