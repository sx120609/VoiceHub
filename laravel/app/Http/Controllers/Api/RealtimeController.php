<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\RoleHelper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RealtimeController extends Controller
{
    private const MUSIC_STATE_CACHE_KEY = 'voicehub:music:latest_state';

    public function progressId(Request $request): JsonResponse
    {
        $user = $this->resolveAdminUser($request);
        if ($user === null) {
            return response()->json(['message' => '需要管理员权限'], 403);
        }

        return response()->json([
            'id' => 'progress_'.round(microtime(true) * 1000).'_'.bin2hex(random_bytes(4)),
        ]);
    }

    public function progressEvents(Request $request): StreamedResponse|JsonResponse
    {
        $user = $this->resolveAdminUser($request);
        if ($user === null) {
            return response()->json(['message' => '需要管理员权限'], 403);
        }

        $id = trim((string) $request->query('id', ''));
        if ($id === '') {
            return response()->json(['message' => '缺少进度ID'], 400);
        }

        return $this->sseResponse(function () use ($id): void {
            echo 'data: '.json_encode(['connected' => true, 'id' => $id], JSON_UNESCAPED_UNICODE)."\n\n";
            @ob_flush();
            @flush();

            for ($i = 0; $i < 120; $i++) {
                if (connection_aborted()) {
                    break;
                }
                echo ": heartbeat\n\n";
                @ob_flush();
                @flush();
                sleep(1);
            }
        });
    }

    public function musicState(Request $request): JsonResponse
    {
        $body = $request->all();
        if (!is_array($body) || !isset($body['type'])) {
            return response()->json(['message' => '无效的请求数据'], 400);
        }

        $type = (string) $body['type'];
        $data = is_array($body['data'] ?? null) ? $body['data'] : [];

        if (!in_array($type, ['state_update', 'song_change', 'position_update'], true)) {
            return response()->json(['message' => '不支持的操作类型'], 400);
        }

        $normalized = [
            'type' => $type,
            'data' => [
                'songId' => isset($data['songId']) ? (int) $data['songId'] : null,
                'isPlaying' => (bool) ($data['isPlaying'] ?? false),
                'position' => isset($data['position']) ? (float) $data['position'] : 0,
                'duration' => isset($data['duration']) ? (float) $data['duration'] : 0,
                'volume' => isset($data['volume']) ? (float) $data['volume'] : 1,
                'playlistIndex' => isset($data['playlistIndex']) ? (int) $data['playlistIndex'] : null,
                'title' => (string) ($data['title'] ?? ''),
                'artist' => (string) ($data['artist'] ?? ''),
                'cover' => (string) ($data['cover'] ?? ''),
            ],
            'timestamp' => round(microtime(true) * 1000),
        ];

        Cache::put(self::MUSIC_STATE_CACHE_KEY, $normalized, now()->addHours(6));

        return response()->json([
            'success' => true,
            'message' => $type === 'song_change' ? '歌曲切换已广播' : '音乐状态已更新',
        ]);
    }

    public function musicWebsocket(Request $request): StreamedResponse
    {
        $connectionId = 'music_'.round(microtime(true) * 1000).'_'.bin2hex(random_bytes(4));
        $lastTimestamp = 0;

        return $this->sseResponse(function () use ($connectionId, &$lastTimestamp): void {
            echo 'data: '.json_encode([
                'type' => 'connection_established',
                'data' => [
                    'connectionId' => $connectionId,
                    'timestamp' => round(microtime(true) * 1000),
                ],
            ], JSON_UNESCAPED_UNICODE)."\n\n";
            @ob_flush();
            @flush();

            for ($i = 0; $i < 120; $i++) {
                if (connection_aborted()) {
                    break;
                }

                $current = Cache::get(self::MUSIC_STATE_CACHE_KEY);
                if (is_array($current) && (int) ($current['timestamp'] ?? 0) > $lastTimestamp) {
                    $lastTimestamp = (int) $current['timestamp'];
                    echo 'data: '.json_encode([
                        'type' => $this->musicEventType((string) ($current['type'] ?? 'state_update')),
                        'data' => $current['data'] ?? [],
                    ], JSON_UNESCAPED_UNICODE)."\n\n";
                } else {
                    echo 'data: '.json_encode([
                        'type' => 'heartbeat',
                        'data' => ['timestamp' => round(microtime(true) * 1000)],
                    ], JSON_UNESCAPED_UNICODE)."\n\n";
                }
                @ob_flush();
                @flush();
                sleep(1);
            }
        });
    }

    private function musicEventType(string $type): string
    {
        return match ($type) {
            'song_change' => 'song_change',
            'position_update' => 'music_state_update',
            default => 'music_state_update',
        };
    }

    private function sseResponse(\Closure $callback): StreamedResponse
    {
        return response()->stream($callback, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Headers' => 'Cache-Control',
        ]);
    }

    private function resolveAdminUser(Request $request): ?User
    {
        /** @var User|null $requestUser */
        $requestUser = $request->user();
        if ($requestUser !== null && RoleHelper::isAdminLike($requestUser->role)) {
            return $requestUser;
        }

        $queryToken = trim((string) $request->query('token', ''));
        $cookieToken = trim((string) $request->cookie('auth-token', ''));
        $bearerToken = trim((string) ($request->bearerToken() ?? ''));
        $token = $queryToken !== '' ? $queryToken : ($cookieToken !== '' ? $cookieToken : $bearerToken);
        if ($token === '') {
            return null;
        }

        try {
            $payload = \App\Support\JwtToken::verify($token);
            $userId = (int) ($payload['userId'] ?? 0);
            if ($userId <= 0) {
                return null;
            }

            $user = User::query()->find($userId);
            if ($user === null || !RoleHelper::isAdminLike($user->role)) {
                return null;
            }

            return $user;
        } catch (\Throwable) {
            return null;
        }
    }
}
