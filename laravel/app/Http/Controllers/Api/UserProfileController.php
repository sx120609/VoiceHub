<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserProfileController extends Controller
{
    public function update(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        if ($user === null) {
            return response()->json(['message' => '需要登录后才能更新资料'], 401);
        }

        $validated = $request->validate([
            'displayName' => ['required', 'string', 'max:30'],
        ]);

        $displayName = trim($validated['displayName']);
        if ($displayName === '') {
            return response()->json(['message' => '显示昵称不能为空'], 400);
        }

        $user->forceFill([
            'name' => $displayName,
            'updatedAt' => now(),
        ])->save();

        return response()->json([
            'success' => true,
            'message' => '显示昵称保存成功',
            'data' => [
                'name' => $user->name,
            ],
        ]);
    }
}
