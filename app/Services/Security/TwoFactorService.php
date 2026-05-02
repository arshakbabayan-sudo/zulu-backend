<?php

namespace App\Services\Security;

use App\Models\User;
use App\Models\UserTwoFactor;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;
use RuntimeException;

/**
 * PART 29 — TOTP-based 2FA for users.
 *
 * Two-stage activation: setup() generates a secret + QR + recovery codes,
 * then confirm(code) validates the user has scanned the QR before flipping
 * the account into 2FA-enabled state.
 */
class TwoFactorService
{
    public const RECOVERY_CODE_COUNT = 8;

    public const ISSUER = 'ZULU';

    public function __construct(
        private TotpGenerator $totp,
    ) {}

    /**
     * Begin enrolment. Returns secret + QR URI + recovery codes (display once).
     *
     * @return array{secret: string, qr_uri: string, recovery_codes: array<int, string>}
     */
    public function setup(User $user): array
    {
        if ($this->isEnabled($user)) {
            throw new RuntimeException('2FA already enabled. Disable first to re-enrol.');
        }

        $secret = $this->totp->generateSecret();
        $recovery = $this->generateRecoveryCodes();

        UserTwoFactor::query()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'secret_encrypted' => Crypt::encryptString($secret),
                'recovery_codes_encrypted' => $this->hashRecoveryCodes($recovery),
                'enabled_at' => null,
                'confirmed_at' => null,
            ]
        );

        $qrUri = $this->totp->provisioningUri(
            (string) $user->email,
            self::ISSUER,
            $secret
        );

        return [
            'secret' => $secret,
            'qr_uri' => $qrUri,
            'recovery_codes' => $recovery,
        ];
    }

    /**
     * Finish enrolment by validating the user can produce a current TOTP.
     */
    public function confirm(User $user, string $code): bool
    {
        $tf = $this->record($user);
        if ($tf === null) {
            throw new RuntimeException('No 2FA setup in progress. Call setup() first.');
        }
        if ($tf->isEnabled()) {
            return true; // already enabled — idempotent
        }

        $secret = Crypt::decryptString($tf->secret_encrypted);
        if (! $this->totp->verify($secret, $code)) {
            return false;
        }

        $tf->enabled_at = now();
        $tf->confirmed_at = now();
        $tf->last_verified_at = now();
        $tf->save();

        return true;
    }

    /**
     * Verify a TOTP code OR a single-use recovery code for an enabled user.
     * Returns true on success.
     */
    public function verify(User $user, string $code): bool
    {
        $tf = $this->record($user);
        if ($tf === null || ! $tf->isEnabled()) {
            return false;
        }

        $secret = Crypt::decryptString($tf->secret_encrypted);
        if ($this->totp->verify($secret, $code)) {
            $tf->last_verified_at = now();
            $tf->save();

            return true;
        }

        return $this->consumeRecoveryCode($tf, $code);
    }

    /**
     * Disable 2FA. Requires the user's password to confirm intent.
     */
    public function disable(User $user, string $currentPassword): bool
    {
        if (! Hash::check($currentPassword, $user->password)) {
            throw new InvalidArgumentException('Password is incorrect.');
        }

        $tf = $this->record($user);
        if ($tf === null) {
            return false;
        }

        DB::transaction(function () use ($tf): void {
            $tf->delete();
        });

        return true;
    }

    /**
     * @return array<int, string>
     */
    public function regenerateRecoveryCodes(User $user): array
    {
        $tf = $this->record($user);
        if ($tf === null || ! $tf->isEnabled()) {
            throw new RuntimeException('2FA must be enabled to regenerate recovery codes.');
        }

        $codes = $this->generateRecoveryCodes();
        $tf->recovery_codes_encrypted = $this->hashRecoveryCodes($codes);
        $tf->save();

        return $codes;
    }

    public function isEnabled(User $user): bool
    {
        $tf = $this->record($user);

        return $tf !== null && $tf->isEnabled();
    }

    private function record(User $user): ?UserTwoFactor
    {
        return UserTwoFactor::query()->where('user_id', $user->id)->first();
    }

    /**
     * @return array<int, string>
     */
    private function generateRecoveryCodes(): array
    {
        $codes = [];
        for ($i = 0; $i < self::RECOVERY_CODE_COUNT; $i++) {
            // 10-character alphanumeric code, displayed as XXXXX-XXXXX
            $raw = strtoupper(substr(bin2hex(random_bytes(8)), 0, 10));
            $codes[] = substr($raw, 0, 5).'-'.substr($raw, 5, 5);
        }

        return $codes;
    }

    /**
     * @param  array<int, string>  $codes
     * @return array<int, array{hash: string, used_at: ?string}>
     */
    private function hashRecoveryCodes(array $codes): array
    {
        return array_map(fn (string $c) => [
            'hash' => hash('sha256', $c),
            'used_at' => null,
        ], $codes);
    }

    private function consumeRecoveryCode(UserTwoFactor $tf, string $code): bool
    {
        $codes = $tf->recovery_codes_encrypted ?? [];
        if (! is_array($codes)) {
            return false;
        }

        $hash = hash('sha256', $code);
        $changed = false;

        foreach ($codes as &$entry) {
            if (($entry['hash'] ?? null) === $hash && empty($entry['used_at'])) {
                $entry['used_at'] = now()->toIso8601String();
                $changed = true;
                break;
            }
        }
        unset($entry);

        if (! $changed) {
            return false;
        }

        $tf->recovery_codes_encrypted = $codes;
        $tf->last_verified_at = now();
        $tf->save();

        return true;
    }
}
