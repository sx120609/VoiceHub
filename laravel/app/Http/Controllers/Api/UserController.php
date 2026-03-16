<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\AvatarHelper;
use App\Support\RoleHelper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UserController extends Controller
{
    public function profile(Request $request): JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->user();

        $user = DB::table('User')
            ->select(['id', 'username', 'name', 'email', 'role'])
            ->where('id', $authUser->id)
            ->first();

        if ($user === null) {
            return response()->json(['message' => '用户不存在'], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'id' => (int) $user->id,
                'username' => $user->username,
                'displayName' => $user->name ?: $user->username,
                'email' => $user->email ?: '',
                'role' => RoleHelper::normalizeRoleOrDefault($user->role, 'USER'),
            ],
        ]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $displayName = trim((string) $request->input('displayName', ''));
        if ($displayName === '') {
            return response()->json(['message' => '显示昵称不能为空'], 400);
        }
        if (mb_strlen($displayName) > 30) {
            return response()->json(['message' => '显示昵称不能超过30个字符'], 400);
        }

        DB::table('User')
            ->where('id', $user->id)
            ->update([
                'name' => $displayName,
                'updatedAt' => now(),
            ]);

        $updated = DB::table('User')
            ->select(['id', 'username', 'name'])
            ->where('id', $user->id)
            ->first();

        return response()->json([
            'success' => true,
            'data' => [
                'id' => (int) ($updated->id ?? $user->id),
                'username' => $updated->username ?? $user->username,
                'displayName' => ($updated->name ?? null) ?: ($updated->username ?? $user->username),
            ],
            'message' => '显示昵称更新成功',
        ]);
    }

    public function search(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $keyword = trim((string) $request->query('keyword', ''));
        if ($keyword === '') {
            return response()->json([
                'success' => true,
                'users' => [],
            ]);
        }

        $results = DB::table('User')
            ->select(['id', 'name', 'username', 'class', 'grade'])
            ->where('id', '<>', $user->id)
            ->where('status', 'active')
            ->where(function ($query) use ($keyword): void {
                $query->whereRaw('"name" ILIKE ?', ['%'.$keyword.'%'])
                    ->orWhereRaw('"username" ILIKE ?', ['%'.$keyword.'%']);
            })
            ->limit(10)
            ->get()
            ->map(function ($item) {
                $name = (string) ($item->name ?? '');
                if ($name !== '' && mb_strlen($name) > 2) {
                    $item->name = mb_substr($name, 0, 1).str_repeat('*', mb_strlen($name) - 2).mb_substr($name, -1);
                }

                return $item;
            });

        return response()->json([
            'success' => true,
            'users' => $results,
        ]);
    }

    public function socialAccounts(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $identities = DB::table('UserIdentity')
            ->select(['provider', 'providerUserId', 'providerUsername', 'createdAt'])
            ->where('userId', $user->id)
            ->get();

        $userRow = DB::table('User')
            ->select(['username', 'email', 'avatar'])
            ->where('id', $user->id)
            ->first();

        return response()->json([
            'success' => true,
            'data' => [
                'accounts' => $identities,
                'avatar' => AvatarHelper::resolvePreferredAvatar($userRow->avatar ?? null, $userRow->username ?? null, $userRow->email ?? null),
            ],
        ]);
    }
}
