<?php

namespace App\Support;

use Illuminate\Support\Facades\File;

final class AvatarHelper
{
    private const AVATAR_FILENAME_REGEX = '/^[A-Za-z0-9._-]+$/';

    /**
     * @return string[]
     */
    public static function getAvatarStorageDirs(): array
    {
        $envDir = trim((string) env('AVATAR_UPLOAD_DIR', ''));
        $resolvedEnvDir = $envDir === '' ? null : (str_starts_with($envDir, '/') ? $envDir : base_path($envDir));

        $ordered = array_filter([
            $resolvedEnvDir,
            storage_path('app/uploads/avatars'),
            base_path('storage/uploads/avatars'),
            public_path('uploads/avatars'),
        ]);

        return array_values(array_unique($ordered));
    }

    public static function ensurePrimaryAvatarStorageDir(): string
    {
        $dirs = self::getAvatarStorageDirs();
        foreach ($dirs as $dir) {
            try {
                File::ensureDirectoryExists($dir);
                if (is_writable($dir)) {
                    return $dir;
                }
            } catch (\Throwable) {
                // Try next path.
            }
        }

        $fallback = $dirs[0] ?? storage_path('app/uploads/avatars');
        File::ensureDirectoryExists($fallback);

        return $fallback;
    }

    public static function appBasePrefix(): string
    {
        $rawBase = trim((string) env('APP_BASE_PATH', '/rareapp'));
        if ($rawBase === '' || $rawBase === '/') {
            return '';
        }

        $normalized = '/'.trim($rawBase, '/');
        if ($normalized === '/api') {
            return '';
        }

        return $normalized;
    }

    public static function buildAvatarApiPath(string $fileName): string
    {
        return self::appBasePrefix().'/api/user/avatar-file/'.$fileName;
    }

    public static function extractAvatarFileName(?string $avatar): ?string
    {
        if ($avatar === null) {
            return null;
        }

        $normalized = trim($avatar);
        if ($normalized === '') {
            return null;
        }

        $markers = ['/api/user/avatar-file/', '/uploads/avatars/'];
        foreach ($markers as $marker) {
            $markerPos = strpos($normalized, $marker);
            if ($markerPos === false) {
                continue;
            }

            $rawName = substr($normalized, $markerPos + strlen($marker));
            $rawName = explode('?', $rawName)[0];
            $rawName = explode('#', $rawName)[0];
            $decoded = rawurldecode(trim($rawName));

            if ($decoded !== '' && preg_match(self::AVATAR_FILENAME_REGEX, $decoded) === 1) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * @return string[]
     */
    public static function resolveStoredAvatarPaths(?string $avatar): array
    {
        $fileName = self::extractAvatarFileName($avatar);
        if ($fileName === null) {
            return [];
        }

        return array_map(
            static fn (string $dir): string => rtrim($dir, '/').'/'.$fileName,
            self::getAvatarStorageDirs()
        );
    }

    public static function resolvePreferredAvatar(?string $customAvatar, ?string $username, ?string $email): ?string
    {
        return self::normalizeCustomAvatar($customAvatar) ?? self::resolveQQAvatar($username, $email);
    }

    public static function isValidAvatarFileName(string $value): bool
    {
        return preg_match(self::AVATAR_FILENAME_REGEX, $value) === 1;
    }

    private static function normalizeCustomAvatar(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim($value);
        if ($normalized === '') {
            return null;
        }

        if (str_contains($normalized, '/api/user/avatar-file/')) {
            return $normalized;
        }

        $marker = '/uploads/avatars/';
        $markerPos = strpos($normalized, $marker);
        if ($markerPos === false) {
            return $normalized;
        }

        $prefix = rtrim(substr($normalized, 0, $markerPos), '/');
        $rawName = substr($normalized, $markerPos + strlen($marker));
        $rawName = explode('?', $rawName)[0];
        $rawName = explode('#', $rawName)[0];
        $decoded = rawurldecode(trim($rawName));
        if ($decoded === '' || !self::isValidAvatarFileName($decoded)) {
            return $normalized;
        }

        return $prefix.'/api/user/avatar-file/'.$decoded;
    }

    private static function resolveQQAvatar(?string $username, ?string $email): ?string
    {
        $qqNumber = self::normalizeQQNumber($username);
        if ($qqNumber === null && $email !== null) {
            $normalizedEmail = strtolower(trim($email));
            if (str_ends_with($normalizedEmail, '@qq.com')) {
                $qqNumber = self::normalizeQQNumber(substr($normalizedEmail, 0, -strlen('@qq.com')));
            }
        }

        if ($qqNumber === null) {
            return null;
        }

        return 'https://q.qlogo.cn/headimg_dl?dst_uin='.$qqNumber.'&spec=640&img_type=jpg';
    }

    private static function normalizeQQNumber(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim($value);
        if ($normalized === '' || preg_match('/^[1-9]\d{4,10}$/', $normalized) !== 1) {
            return null;
        }

        return $normalized;
    }
}
