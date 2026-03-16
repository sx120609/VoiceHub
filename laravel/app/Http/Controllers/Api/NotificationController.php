<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $page = max(1, (int) $request->query('page', 1));
        $limit = min(50, max(1, (int) $request->query('limit', 10)));
        $offset = ($page - 1) * $limit;
        $removedTypes = ['COLLABORATION_INVITE', 'COLLABORATION_RESPONSE'];

        $baseQuery = DB::table('Notification')
            ->where('userId', $user->id)
            ->whereNotIn('type', $removedTypes);

        $totalCount = (int) (clone $baseQuery)->count();
        $totalPages = $totalCount > 0 ? (int) ceil($totalCount / $limit) : 0;

        $notifications = (clone $baseQuery)
            ->orderByDesc('createdAt')
            ->offset($offset)
            ->limit($limit)
            ->get();

        $unreadCount = (int) DB::table('Notification')
            ->where('userId', $user->id)
            ->where('read', false)
            ->whereNotIn('type', $removedTypes)
            ->count();

        return response()->json([
            'notifications' => $notifications,
            'unreadCount' => $unreadCount,
            'pagination' => [
                'currentPage' => $page,
                'totalPages' => $totalPages,
                'totalCount' => $totalCount,
                'limit' => $limit,
                'hasNextPage' => $page < $totalPages,
                'hasPrevPage' => $page > 1,
            ],
        ]);
    }

    public function getSettings(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $userInfo = DB::table('User')
            ->select(['meowNickname', 'email', 'emailVerified'])
            ->where('id', $user->id)
            ->first();

        $settings = DB::table('NotificationSettings')
            ->where('userId', $user->id)
            ->first();

        if ($settings === null) {
            $id = DB::table('NotificationSettings')->insertGetId([
                'createdAt' => now(),
                'updatedAt' => now(),
                'userId' => $user->id,
                'enabled' => true,
                'songRequestEnabled' => true,
                'songVotedEnabled' => true,
                'songPlayedEnabled' => true,
                'songCommentEnabled' => true,
                'refreshInterval' => 60,
                'songVotedThreshold' => 1,
            ], 'id');
            $settings = DB::table('NotificationSettings')->where('id', $id)->first();
        }

        return response()->json([
            'success' => true,
            'data' => [
                'id' => (int) $settings->id,
                'userId' => (int) $settings->userId,
                'songSelectedNotify' => (bool) $settings->songRequestEnabled,
                'songPlayedNotify' => (bool) $settings->songPlayedEnabled,
                'songVotedNotify' => (bool) $settings->songVotedEnabled,
                'songCommentNotify' => (bool) ($settings->songCommentEnabled ?? true),
                'systemNotify' => (bool) $settings->enabled,
                'refreshInterval' => (int) $settings->refreshInterval,
                'songVotedThreshold' => (int) $settings->songVotedThreshold,
                'meowUserId' => (string) ($userInfo->meowNickname ?? ''),
                'userEmail' => (string) ($userInfo->email ?? ''),
                'emailVerified' => (bool) ($userInfo->emailVerified ?? false),
            ],
        ]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $body = $request->all();

        $existing = DB::table('NotificationSettings')
            ->where('userId', $user->id)
            ->first();

        if ($existing === null) {
            DB::table('NotificationSettings')->insert([
                'createdAt' => now(),
                'updatedAt' => now(),
                'userId' => $user->id,
                'enabled' => array_key_exists('systemNotify', $body) ? (bool) $body['systemNotify'] : true,
                'songRequestEnabled' => array_key_exists('songSelectedNotify', $body) ? (bool) $body['songSelectedNotify'] : true,
                'songPlayedEnabled' => array_key_exists('songPlayedNotify', $body) ? (bool) $body['songPlayedNotify'] : true,
                'songVotedEnabled' => array_key_exists('songVotedNotify', $body) ? (bool) $body['songVotedNotify'] : true,
                'songCommentEnabled' => array_key_exists('songCommentNotify', $body) ? (bool) $body['songCommentNotify'] : true,
                'songVotedThreshold' => max(1, min(10, (int) ($body['songVotedThreshold'] ?? 1))),
                'refreshInterval' => max(10, min(300, (int) ($body['refreshInterval'] ?? 60))),
            ]);
        } else {
            DB::table('NotificationSettings')
                ->where('userId', $user->id)
                ->update([
                    'updatedAt' => now(),
                    'enabled' => array_key_exists('systemNotify', $body) ? (bool) $body['systemNotify'] : (bool) $existing->enabled,
                    'songRequestEnabled' => array_key_exists('songSelectedNotify', $body) ? (bool) $body['songSelectedNotify'] : (bool) $existing->songRequestEnabled,
                    'songPlayedEnabled' => array_key_exists('songPlayedNotify', $body) ? (bool) $body['songPlayedNotify'] : (bool) $existing->songPlayedEnabled,
                    'songVotedEnabled' => array_key_exists('songVotedNotify', $body) ? (bool) $body['songVotedNotify'] : (bool) $existing->songVotedEnabled,
                    'songCommentEnabled' => array_key_exists('songCommentNotify', $body) ? (bool) $body['songCommentNotify'] : (bool) ($existing->songCommentEnabled ?? true),
                    'songVotedThreshold' => array_key_exists('songVotedThreshold', $body)
                        ? max(1, min(10, (int) $body['songVotedThreshold']))
                        : (int) $existing->songVotedThreshold,
                    'refreshInterval' => array_key_exists('refreshInterval', $body)
                        ? max(10, min(300, (int) $body['refreshInterval']))
                        : (int) $existing->refreshInterval,
                ]);
        }

        return $this->getSettings($request);
    }

    public function markRead(Request $request, int $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $notification = DB::table('Notification')
            ->where('id', $id)
            ->first();

        if ($notification === null) {
            return response()->json(['message' => '通知不存在'], 404);
        }

        if ((int) $notification->userId !== (int) $user->id) {
            return response()->json(['message' => '无权标记此通知'], 403);
        }

        DB::table('Notification')
            ->where('id', $id)
            ->update(['read' => true, 'updatedAt' => now()]);

        $updated = DB::table('Notification')->where('id', $id)->first();

        return response()->json($updated);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $count = DB::table('Notification')
            ->where('userId', $user->id)
            ->where('read', false)
            ->update(['read' => true, 'updatedAt' => now()]);

        return response()->json([
            'success' => true,
            'count' => $count,
        ]);
    }

    public function delete(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $id = (int) ($request->input('notificationId') ?? 0);
        if ($id <= 0) {
            return response()->json(['message' => '无效的通知ID'], 400);
        }

        $notification = DB::table('Notification')->where('id', $id)->first();
        if ($notification === null) {
            return response()->json(['message' => '通知不存在'], 404);
        }
        if ((int) $notification->userId !== (int) $user->id) {
            return response()->json(['message' => '无权删除此通知'], 403);
        }

        DB::table('Notification')->where('id', $id)->delete();

        return response()->json(['success' => true]);
    }

    public function deleteById(Request $request, int $id): JsonResponse
    {
        $request->merge(['notificationId' => $id]);

        return $this->delete($request);
    }

    public function clearAll(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $count = DB::table('Notification')
            ->where('userId', $user->id)
            ->delete();

        return response()->json([
            'success' => true,
            'count' => $count,
        ]);
    }
}
