<?php

namespace App\Services\Security;

use App\Mail\TwoFactorCodeMail;
use App\Models\User;
use App\Models\UserTwoFactor;
use Illuminate\Support\Facades\Mail;

/**
 * Email-channel 2FA — companion to {@see TwoFactorService} (TOTP).
 *
 * Email codes are stateless from the user's perspective: there is no QR
 * scan, no enrolment step, no recovery codes. At login time the server
 * generates a fresh 6-digit code, stores its sha256 + a 10-minute TTL on
 * the user's `user_two_factor` row, and emails the cleartext code. The
 * verify endpoint compares hashes, clears the stored value on success.
 *
 * Reuses the existing user_two_factor table (one row per user) so that:
 *   - `last_verified_at` etc. metadata is uniform across both channels;
 *   - switching method (totp ↔ email) doesn't require a row migration;
 *   - the same throttle scope `2fa.*` route group applies.
 */
class EmailTwoFactorService
{
    public const CODE_TTL_MINUTES = 10;

    public const CODE_LENGTH = 6;

    /**
     * Generate a fresh 6-digit code, persist its hash + TTL on the user's
     * user_two_factor row, and email the cleartext to the user.
     *
     * Returns the cleartext code so tests can assert without scraping the
     * mailer fake. Do NOT return this from any HTTP endpoint.
     */
    public function generateAndSend(User $user): string
    {
        $code = $this->randomCode();

        UserTwoFactor::query()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'email_code_hash' => hash('sha256', $code),
                'email_code_expires_at' => now()->addMinutes(self::CODE_TTL_MINUTES),
            ]
        );

        Mail::to($user->email)->send(new TwoFactorCodeMail($user, $code));

        return $code;
    }

    /**
     * Validate the user-supplied code. Constant-time hash comparison; the
     * stored hash is cleared on success so the same code cannot be replayed.
     */
    public function verify(User $user, string $code): bool
    {
        $tf = UserTwoFactor::query()->where('user_id', $user->id)->first();
        if ($tf === null) {
            return false;
        }
        if ($tf->email_code_hash === null || $tf->email_code_expires_at === null) {
            return false;
        }
        if ($tf->email_code_expires_at->isPast()) {
            return false;
        }

        $candidate = hash('sha256', trim($code));
        if (! hash_equals($tf->email_code_hash, $candidate)) {
            return false;
        }

        $tf->email_code_hash = null;
        $tf->email_code_expires_at = null;
        $tf->last_verified_at = now();
        $tf->save();

        return true;
    }

    private function randomCode(): string
    {
        // random_int is cryptographically secure (PHP wraps the OS CSPRNG).
        $max = (10 ** self::CODE_LENGTH) - 1;

        return str_pad((string) random_int(0, $max), self::CODE_LENGTH, '0', STR_PAD_LEFT);
    }
}
