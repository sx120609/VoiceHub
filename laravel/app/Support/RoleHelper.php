<?php

namespace App\Support;

final class RoleHelper
{
    /**
     * @var array<string, string>
     */
    private const ROLE_ALIASES = [
        'USER' => 'USER',
        'SONG_ADMIN' => 'SONG_ADMIN',
        'SONGADMIN' => 'SONG_ADMIN',
        'ADMIN' => 'ADMIN',
        'SUPER_ADMIN' => 'SUPER_ADMIN',
        'SUPERADMIN' => 'SUPER_ADMIN',
    ];

    public static function normalizeRole(?string $role): ?string
    {
        if ($role === null) {
            return null;
        }

        $normalized = strtoupper(str_replace(['-', ' '], '_', trim($role)));
        if ($normalized === '') {
            return null;
        }

        return self::ROLE_ALIASES[$normalized] ?? null;
    }

    public static function normalizeRoleOrDefault(?string $role, string $fallback = 'USER'): string
    {
        return self::normalizeRole($role) ?? $fallback;
    }

    public static function isSongAdminLike(?string $role): bool
    {
        $normalized = self::normalizeRole($role);

        return in_array($normalized, ['SONG_ADMIN', 'ADMIN', 'SUPER_ADMIN'], true);
    }

    public static function isAdminLike(?string $role): bool
    {
        $normalized = self::normalizeRole($role);

        return in_array($normalized, ['ADMIN', 'SUPER_ADMIN'], true);
    }
}
