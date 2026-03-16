<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\AvatarHelper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AvatarController extends Controller
{
    private const ALLOWED_MIME_TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    private const CONTENT_TYPE_BY_EXTENSION = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
    ];

    public function upload(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        if ($user === null) {
            return response()->json(['message' => '需要登录后才能上传头像'], 401);
        }

        $request->validate([
            'avatar' => ['required', 'file', 'max:2048', 'mimetypes:image/jpeg,image/png,image/webp'],
        ]);

        $file = $request->file('avatar');
        if ($file === null) {
            return response()->json(['message' => '请上传头像文件'], 400);
        }

        $mimeType = (string) $file->getMimeType();
        $extension = self::ALLOWED_MIME_TYPES[$mimeType] ?? null;
        if ($extension === null) {
            return response()->json(['message' => '仅支持 JPG / PNG / WEBP 格式图片'], 400);
        }

        $fileName = $user->id.'-'.round(microtime(true) * 1000).'-'.Str::lower(Str::random(8)).'.'.$extension;
        $targetDir = AvatarHelper::ensurePrimaryAvatarStorageDir();
        $targetPath = rtrim($targetDir, '/').'/'.$fileName;
        $oldAvatar = $user->avatar;

        try {
            $file->move($targetDir, $fileName);

            $user->forceFill([
                'avatar' => AvatarHelper::buildAvatarApiPath($fileName),
                'updatedAt' => now(),
            ])->save();

            foreach (AvatarHelper::resolveStoredAvatarPaths($oldAvatar) as $oldPath) {
                if ($oldPath !== $targetPath && is_file($oldPath)) {
                    @unlink($oldPath);
                }
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'avatar' => $user->avatar,
                ],
                'message' => '头像上传成功',
            ]);
        } catch (\Throwable $exception) {
            if (is_file($targetPath)) {
                @unlink($targetPath);
            }

            return response()->json([
                'message' => '头像上传失败',
                'details' => $exception->getMessage(),
            ], 500);
        }
    }

    public function remove(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        if ($user === null) {
            return response()->json(['message' => '需要登录后才能移除头像'], 401);
        }

        $oldAvatar = $user->avatar;
        $oldPaths = AvatarHelper::resolveStoredAvatarPaths($oldAvatar);

        $user->forceFill([
            'avatar' => null,
            'updatedAt' => now(),
        ])->save();

        foreach ($oldPaths as $oldPath) {
            if (is_file($oldPath)) {
                @unlink($oldPath);
            }
        }

        return response()->json([
            'success' => true,
            'data' => [
                'avatar' => null,
            ],
            'message' => $oldAvatar ? '头像已移除' : '当前未设置自定义头像',
        ]);
    }

    public function file(string $name)
    {
        if (!AvatarHelper::isValidAvatarFileName($name)) {
            return response()->json(['message' => '头像文件名无效'], 400);
        }

        foreach (AvatarHelper::getAvatarStorageDirs() as $dir) {
            $path = rtrim($dir, '/').'/'.$name;
            if (!is_file($path)) {
                continue;
            }

            $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            $contentType = self::CONTENT_TYPE_BY_EXTENSION[$extension] ?? 'application/octet-stream';

            return response()->file($path, [
                'Content-Type' => $contentType,
                'Cache-Control' => 'public, max-age=86400, s-maxage=86400',
            ]);
        }

        return response()->json(['message' => '头像文件不存在'], 404);
    }
}
