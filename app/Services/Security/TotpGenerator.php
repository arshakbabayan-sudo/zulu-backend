<?php

namespace App\Services\Security;

/**
 * Pure-PHP TOTP (RFC 6238) implementation. 30-second time step, 6-digit codes,
 * SHA-1, ±1 step drift tolerance.
 *
 * Compatible with Google Authenticator, Authy, 1Password, etc.
 */
class TotpGenerator
{
    public const PERIOD = 30;

    public const DIGITS = 6;

    public const ALGO = 'sha1';

    /**
     * Generate a random Base32 secret (160 bits = 32 chars).
     */
    public function generateSecret(): string
    {
        return $this->base32Encode(random_bytes(20));
    }

    /**
     * Build the otpauth URI for QR code rendering.
     */
    public function provisioningUri(string $accountLabel, string $issuer, string $base32Secret): string
    {
        return sprintf(
            'otpauth://totp/%s:%s?secret=%s&issuer=%s&algorithm=SHA1&digits=%d&period=%d',
            rawurlencode($issuer),
            rawurlencode($accountLabel),
            $base32Secret,
            rawurlencode($issuer),
            self::DIGITS,
            self::PERIOD,
        );
    }

    /**
     * Verify a code against the secret with ±1 step (=±30s) tolerance.
     */
    public function verify(string $base32Secret, string $code, ?int $atTime = null): bool
    {
        $code = preg_replace('/\s+/', '', $code);
        if (! preg_match('/^\d{'.self::DIGITS.'}$/', $code)) {
            return false;
        }

        $time = (int) floor(($atTime ?? time()) / self::PERIOD);

        for ($delta = -1; $delta <= 1; $delta++) {
            if (hash_equals($this->codeAt($base32Secret, $time + $delta), $code)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Compute the code for a specific time step (used internally + for tests).
     */
    public function codeAt(string $base32Secret, int $timeStep): string
    {
        $key = $this->base32Decode($base32Secret);
        $binTime = pack('N*', 0, $timeStep); // 8 bytes big-endian

        $hash = hash_hmac(self::ALGO, $binTime, $key, true);
        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;

        $value = ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF);

        $code = $value % (10 ** self::DIGITS);

        return str_pad((string) $code, self::DIGITS, '0', STR_PAD_LEFT);
    }

    private function base32Encode(string $bytes): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $result = '';
        foreach (str_split($bits, 5) as $chunk) {
            if (strlen($chunk) < 5) {
                $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            }
            $result .= $alphabet[bindec($chunk)];
        }

        return $result;
    }

    private function base32Decode(string $base32): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $base32 = strtoupper(rtrim($base32, '='));

        $bits = '';
        foreach (str_split($base32) as $char) {
            $idx = strpos($alphabet, $char);
            if ($idx === false) {
                continue;
            }
            $bits .= str_pad(decbin($idx), 5, '0', STR_PAD_LEFT);
        }

        $bytes = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $bytes .= chr(bindec($chunk));
            }
        }

        return $bytes;
    }
}
