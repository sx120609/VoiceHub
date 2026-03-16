<?php

namespace App\Support;

use RuntimeException;

final class JwtToken
{
    private const ALGORITHM = 'HS256';

    public static function generate(int $userId, string $role, int $ttlSeconds = 604800): string
    {
        $issuedAt = time();
        $payload = [
            'userId' => $userId,
            'role' => $role,
            'iat' => $issuedAt,
            'exp' => $issuedAt + $ttlSeconds,
            'jti' => bin2hex(random_bytes(8)),
        ];

        $header = [
            'alg' => self::ALGORITHM,
            'typ' => 'JWT',
        ];

        $headerEncoded = self::base64UrlEncode(json_encode($header, JSON_UNESCAPED_SLASHES));
        $payloadEncoded = self::base64UrlEncode(json_encode($payload, JSON_UNESCAPED_SLASHES));
        $signature = hash_hmac('sha256', $headerEncoded.'.'.$payloadEncoded, self::secret(), true);

        return $headerEncoded.'.'.$payloadEncoded.'.'.self::base64UrlEncode($signature);
    }

    /**
     * @return array<string, mixed>
     */
    public static function verify(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new RuntimeException('INVALID_TOKEN');
        }

        [$headerEncoded, $payloadEncoded, $signatureEncoded] = $parts;

        $headerJson = self::base64UrlDecode($headerEncoded);
        $payloadJson = self::base64UrlDecode($payloadEncoded);
        $signature = self::base64UrlDecode($signatureEncoded);

        $header = json_decode($headerJson, true);
        $payload = json_decode($payloadJson, true);

        if (!is_array($header) || !is_array($payload)) {
            throw new RuntimeException('INVALID_TOKEN');
        }

        if (($header['alg'] ?? null) !== self::ALGORITHM) {
            throw new RuntimeException('INVALID_TOKEN');
        }

        $expectedSignature = hash_hmac('sha256', $headerEncoded.'.'.$payloadEncoded, self::secret(), true);
        if (!hash_equals($expectedSignature, $signature)) {
            throw new RuntimeException('INVALID_TOKEN');
        }

        $exp = $payload['exp'] ?? null;
        if (!is_int($exp) && !ctype_digit((string) $exp)) {
            throw new RuntimeException('INVALID_TOKEN');
        }

        if ((int) $exp < time()) {
            throw new RuntimeException('TOKEN_EXPIRED');
        }

        if (!isset($payload['userId'])) {
            throw new RuntimeException('INVALID_TOKEN');
        }

        return $payload;
    }

    private static function secret(): string
    {
        $secret = (string) env('JWT_SECRET', '');
        if ($secret !== '') {
            return $secret;
        }

        $appKey = (string) config('app.key', '');
        if (str_starts_with($appKey, 'base64:')) {
            $decoded = base64_decode(substr($appKey, 7), true);
            if ($decoded !== false) {
                return $decoded;
            }
        }

        if ($appKey !== '') {
            return $appKey;
        }

        throw new RuntimeException('JWT_SECRET_NOT_CONFIGURED');
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): string
    {
        $padding = strlen($value) % 4;
        if ($padding > 0) {
            $value .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        if ($decoded === false) {
            throw new RuntimeException('INVALID_TOKEN');
        }

        return $decoded;
    }
}
