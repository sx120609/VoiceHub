<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\RoleHelper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;

class AdminBackupController extends Controller
{
    /**
     * @var string[]
     */
    private const ALLOWED_PREFIXES = [
        'database-backup-',
        'users-backup-',
        'users-system-backup-',
        'songs-backup-',
        'songs-system-backup-',
        'system-settings-backup-',
        'uploaded-',
    ];

    public function list(Request $request): JsonResponse
    {
        if ($forbidden = $this->ensureAdmin($request)) {
            return $forbidden;
        }

        $dir = $this->backupDir();
        File::ensureDirectoryExists($dir);

        $items = [];
        foreach (File::files($dir) as $file) {
            $filename = $file->getFilename();
            if (!$this->isAllowedBackupFilename($filename)) {
                continue;
            }

            $metadata = $this->readBackupMetadata($file->getPathname());
            $items[] = [
                'filename' => $filename,
                'size' => $file->getSize(),
                'createdAt' => date(DATE_ATOM, $file->getMTime()),
                'metadata' => $metadata,
                'type' => $this->resolveBackupType($filename, $metadata),
                'isValid' => $metadata !== null,
            ];
        }

        usort($items, static fn ($a, $b) => strtotime((string) $b['createdAt']) <=> strtotime((string) $a['createdAt']));

        return response()->json([
            'success' => true,
            'backups' => $items,
            'total' => count($items),
        ]);
    }

    public function upload(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        if ($forbidden = $this->ensureAdmin($request)) {
            return $forbidden;
        }

        $request->validate([
            'file' => ['required', 'file', 'max:102400', 'mimetypes:application/json,text/plain'],
        ]);

        $uploaded = $request->file('file');
        if ($uploaded === null) {
            return response()->json(['message' => '请选择要上传的备份文件'], 400);
        }

        $rawContent = (string) file_get_contents($uploaded->getRealPath());
        $decoded = json_decode($rawContent, true);
        if (!is_array($decoded) || (!isset($decoded['metadata']) && !isset($decoded['users']))) {
            return response()->json(['message' => '不是有效的备份文件'], 400);
        }

        $dir = $this->backupDir();
        File::ensureDirectoryExists($dir);
        $originalName = $uploaded->getClientOriginalName() ?: 'backup.json';
        $baseName = pathinfo($originalName, PATHINFO_FILENAME);
        $filename = 'uploaded-'.$baseName.'-'.date('Y-m-d\TH-i-s').'.json';
        $path = $dir.'/'.$filename;

        if (!isset($decoded['metadata']) || !is_array($decoded['metadata'])) {
            $decoded['metadata'] = [];
        }
        $decoded['metadata']['uploadedBy'] = $user->username;
        $decoded['metadata']['uploadedAt'] = date(DATE_ATOM);
        $decoded['metadata']['originalFilename'] = $originalName;
        if (!isset($decoded['metadata']['creator']) || $decoded['metadata']['creator'] === '') {
            $decoded['metadata']['creator'] = $user->username;
        }

        file_put_contents($path, json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        $size = filesize($path) ?: 0;

        return response()->json([
            'success' => true,
            'message' => '备份文件上传成功',
            'backup' => [
                'filename' => $filename,
                'originalFilename' => $originalName,
                'size' => $size,
                'uploadedBy' => $user->username,
                'uploadedAt' => date(DATE_ATOM),
                'metadata' => $decoded['metadata'],
            ],
        ]);
    }

    public function download(Request $request)
    {
        if ($forbidden = $this->ensureAdmin($request)) {
            return $forbidden;
        }
        $filename = trim((string) $request->query('filename', ''));
        if ($filename === '') {
            return response()->json(['message' => '缺少文件名参数'], 400);
        }

        return $this->downloadByFilename($request, $filename);
    }

    public function downloadByFilename(Request $request, string $filename)
    {
        if ($forbidden = $this->ensureAdmin($request)) {
            return $forbidden;
        }
        if (!$this->isSafeFilename($filename) || !str_ends_with(strtolower($filename), '.json')) {
            return response()->json(['message' => '无效的文件名'], 400);
        }
        if (!$this->isAllowedBackupFilename($filename) && !str_starts_with($filename, 'uploaded-')) {
            return response()->json(['message' => '无效的备份文件'], 400);
        }

        $path = $this->backupDir().'/'.$filename;
        if (!is_file($path)) {
            return response()->json(['message' => '备份文件不存在'], 404);
        }

        return response()->download($path, $filename, [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'no-cache',
        ]);
    }

    public function delete(Request $request, string $filename): JsonResponse
    {
        if ($forbidden = $this->ensureAdmin($request)) {
            return $forbidden;
        }
        if (!$this->isSafeFilename($filename) || !str_ends_with(strtolower($filename), '.json')) {
            return response()->json(['message' => '无效的文件名'], 400);
        }

        $path = $this->backupDir().'/'.$filename;
        if (!is_file($path)) {
            return response()->json(['message' => '备份文件不存在'], 404);
        }

        @unlink($path);

        return response()->json([
            'success' => true,
            'message' => '备份文件删除成功',
            'filename' => $filename,
        ]);
    }

    private function ensureAdmin(Request $request): ?JsonResponse
    {
        /** @var User|null $user */
        $user = $request->user();
        if ($user === null || !RoleHelper::isAdminLike($user->role)) {
            return response()->json(['message' => '权限不足'], 403);
        }

        return null;
    }

    private function backupDir(): string
    {
        return base_path('backups');
    }

    private function isSafeFilename(string $filename): bool
    {
        if (str_contains($filename, '..') || str_contains($filename, '/') || str_contains($filename, '\\')) {
            return false;
        }

        return preg_match('/^[A-Za-z0-9._-]+$/', $filename) === 1;
    }

    private function isAllowedBackupFilename(string $filename): bool
    {
        if (!str_ends_with(strtolower($filename), '.json')) {
            return false;
        }
        foreach (self::ALLOWED_PREFIXES as $prefix) {
            if (str_starts_with($filename, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readBackupMetadata(string $path): ?array
    {
        try {
            $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            if (is_array($decoded['metadata'] ?? null)) {
                return $decoded['metadata'];
            }
            if (isset($decoded['users']) && is_array($decoded['users'])) {
                return [
                    'version' => '0.1',
                    'timestamp' => $decoded['timestamp'] ?? date(DATE_ATOM, filemtime($path) ?: time()),
                    'creator' => 'system',
                    'description' => '用户数据备份（旧格式）',
                    'tables' => [
                        ['name' => 'users', 'recordCount' => $decoded['totalUsers'] ?? count($decoded['users'])],
                    ],
                    'totalRecords' => $decoded['totalUsers'] ?? count($decoded['users']),
                ];
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }

    /**
     * @param array<string, mixed>|null $metadata
     */
    private function resolveBackupType(string $filename, ?array $metadata): string
    {
        if (str_starts_with($filename, 'database-backup-')) {
            return 'full';
        }
        if (str_starts_with($filename, 'users-backup-') || str_starts_with($filename, 'users-system-backup-')) {
            return 'users';
        }
        if (str_starts_with($filename, 'songs-backup-') || str_starts_with($filename, 'songs-system-backup-')) {
            return 'songs';
        }
        if (str_starts_with($filename, 'system-settings-backup-')) {
            return 'system';
        }
        if (($metadata['backupType'] ?? null) !== null) {
            return (string) $metadata['backupType'];
        }
        if (is_array($metadata['tables'] ?? null) && count($metadata['tables']) > 1) {
            return 'full';
        }

        return 'users';
    }
}
