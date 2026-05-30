<?php

namespace App\Services\Security;

use App\Mail\EmailVerificationCodeMail;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

/**
 * B2C email verification at signup (Arshak 2026-05-31).
 *
 * Generates a 6-digit code, stores its sha256 + 10-min TTL on the user row,
 * emails the cleartext via {@see EmailVerificationCodeMail}. On verify()
 * we constant-time hash_equals, flip `email_verified_at` to now() (the
 * Laravel-standard column other framework features check), and clear the
 * stored code so it can't be replayed.
 *
 * Companion to {@see EmailTwoFactorService} (same pattern, different column
 * triplet). Kept separate so login-time 2FA and signup verification don't
 * trample each other's stored code.
 */
class EmailVerificationCodeService
{
    public const CODE_TTL_MINUTES = 10;

    public const CODE_LENGTH = 6;

    public function generateAndSend(User $user): string
    {
        $code = $this->randomCode();

        $user->forceFill([
            'email_verification_code_hash' => hash('sha256', $code),
            'email_verification_code_expires_at' => now()->addMinutes(self::CODE_TTL_MINUTES),
        ])->saveQuietly();

        Mail::to($user->email)->send(new EmailVerificationCodeMail($user, $code));

        return $code;
    }

    /**
     * Returns true on success (and marks the account verified), false on a
     * miss / expired / no-code-in-flight. Single-use.
     */
    public function verify(User $user, string $code): bool
    {
        if ($user->email_verification_code_hash === null) {
            return false;
        }
        if ($user->email_verification_code_expires_at === null
            || $user->email_verification_code_expires_at->isPast()) {
            return false;
        }

        $candidate = hash('sha256', trim($code));
        if (! hash_equals($user->email_verification_code_hash, $candidate)) {
            return false;
        }

        $user->forceFill([
            'email_verified_at' => now(),
            'email_verification_code_hash' => null,
            'email_verification_code_expires_at' => null,
        ])->saveQuietly();

        return true;
    }

    private function randomCode(): string
    {
        $max = (10 ** self::CODE_LENGTH) - 1;

        return str_pad((string) random_int(0, $max), self::CODE_LENGTH, '0', STR_PAD_LEFT);
    }
}
