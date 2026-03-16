<?php

namespace App\Support;

final class TotpHelper
{
    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function generateSecret(int $length = 32): string
    {
        $length = max(16, $length);
        $bytes = random_bytes((int) ceil($length * 5 / 8));
        $secret = self::base32Encode($bytes);

        return substr($secret, 0, $length);
    }

    public static function buildOtpAuthUri(string $accountName, string $secret, string $issuer = 'VoiceHub'): string
    {
        $label = rawurlencode($issuer.':'.$accountName);
        $issuerParam = rawurlencode($issuer);

        return "otpauth://totp/{$label}?secret={$secret}&issuer={$issuerParam}&algorithm=SHA1&digits=6&period=30";
    }

    public static function verify(string $secret, string $code, int $window = 1): bool
    {
        if (!preg_match('/^\d{6}$/', $code)) {
            return false;
        }

        $timeStep = (int) floor(time() / 30);
        for ($offset = -$window; $offset <= $window; $offset++) {
            if (hash_equals(self::at($secret, $timeStep + $offset), $code)) {
                return true;
            }
        }

        return false;
    }

    public static function at(string $secret, int $timeStep): string
    {
        $key = self::base32Decode(strtoupper($secret));
        $counter = pack('N*', 0).pack('N*', $timeStep);
        $hash = hash_hmac('sha1', $counter, $key, true);
        $offset = ord(substr($hash, -1)) & 0x0F;
        $part = substr($hash, $offset, 4);
        $value = unpack('N', $part)[1] & 0x7FFFFFFF;

        return str_pad((string) ($value % 1000000), 6, '0', STR_PAD_LEFT);
    }

    private static function base32Encode(string $bytes): string
    {
        $binary = '';
        $length = strlen($bytes);
        for ($i = 0; $i < $length; $i++) {
            $binary .= str_pad(decbin(ord($bytes[$i])), 8, '0', STR_PAD_LEFT);
        }

        $chunks = str_split($binary, 5);
        $encoded = '';
        foreach ($chunks as $chunk) {
            if (strlen($chunk) < 5) {
                $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            }
            $encoded .= self::BASE32_ALPHABET[bindec($chunk)];
        }

        return $encoded;
    }

    private static function base32Decode(string $value): string
    {
        $value = preg_replace('/[^A-Z2-7]/', '', $value) ?? '';
        $binary = '';
        $length = strlen($value);
        for ($i = 0; $i < $length; $i++) {
            $index = strpos(self::BASE32_ALPHABET, $value[$i]);
            if ($index === false) {
                continue;
            }
            $binary .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
        }

        $bytes = str_split($binary, 8);
        $decoded = '';
        foreach ($bytes as $byte) {
            if (strlen($byte) !== 8) {
                continue;
            }
            $decoded .= chr(bindec($byte));
        }

        return $decoded;
    }
}
