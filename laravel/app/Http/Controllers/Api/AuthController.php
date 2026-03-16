<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\AvatarHelper;
use App\Support\JwtToken;
use App\Support\RoleHelper;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'username' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ]);

        $rawAccount = trim($validated['username']);
        $normalizedAccount = strtolower($rawAccount);
        $qqPrefixFromEmail = str_ends_with($normalizedAccount, '@qq.com')
            ? substr($normalizedAccount, 0, -strlen('@qq.com'))
            : '';
        $usernameForLookup = $qqPrefixFromEmail !== '' ? $qqPrefixFromEmail : $rawAccount;

        $query = User::query()->select([
            'id',
            'username',
            'name',
            'grade',
            'class',
            'avatar',
            'password',
            'role',
            'status',
            'email',
            'forcePasswordChange',
            'passwordChangedAt',
        ]);

        if ($qqPrefixFromEmail !== '') {
            $query->where(function ($builder) use ($usernameForLookup, $normalizedAccount): void {
                $builder->where('username', $usernameForLookup)->orWhere('email', $normalizedAccount);
            });
        } else {
            $query->where('username', $usernameForLookup);
        }

        $user = $query->first();
        if ($user === null || !Hash::check($validated['password'], (string) $user->password)) {
            return response()->json(['message' => '账号或密码错误'], 401);
        }

        if ($user->status === 'withdrawn') {
            return response()->json(['message' => '该账号已注销'], 403);
        }
        if ($user->status === 'banned') {
            return response()->json(['message' => '该账号已被封禁'], 403);
        }

        $role = RoleHelper::normalizeRoleOrDefault($user->role, 'USER');

        $user->forceFill([
            'lastLogin' => Carbon::now('Asia/Shanghai'),
            'lastLoginIp' => $this->extractClientIp($request),
        ])->save();

        try {
            $token = JwtToken::generate((int) $user->id, $role);
        } catch (\RuntimeException $exception) {
            if ($exception->getMessage() === 'JWT_SECRET_NOT_CONFIGURED') {
                return response()->json(['message' => '服务器配置错误'], 500);
            }
            throw $exception;
        }

        $response = response()->json([
            'success' => true,
            'user' => [
                'id' => (int) $user->id,
                'username' => $user->username,
                'name' => $user->name ?: $user->username,
                'grade' => $user->grade,
                'class' => $user->class,
                'role' => $role,
                'avatar' => AvatarHelper::resolvePreferredAvatar($user->avatar, $user->username, $user->email),
                'needsPasswordChange' => $user->passwordChangedAt === null,
            ],
        ]);

        return $this->attachAuthCookie($request, $response, $token);
    }

    public function verify(Request $request): JsonResponse
    {
        $token = $request->bearerToken() ?: (string) $request->cookie('auth-token', '');
        if ($token === '') {
            return response()->json(['message' => '未提供认证令牌'], 401);
        }

        try {
            $payload = JwtToken::verify($token);
        } catch (\RuntimeException $exception) {
            if ($exception->getMessage() === 'JWT_SECRET_NOT_CONFIGURED') {
                return response()->json(['message' => '服务器配置错误'], 500);
            }

            return $this->clearAuthCookie($request, response()->json(['message' => '令牌无效或已过期'], 401));
        }

        $user = User::query()->select([
            'id',
            'username',
            'name',
            'grade',
            'class',
            'avatar',
            'role',
            'email',
            'forcePasswordChange',
            'passwordChangedAt',
        ])->find((int) ($payload['userId'] ?? 0));

        if ($user === null) {
            return $this->clearAuthCookie($request, response()->json(['message' => '用户不存在'], 401));
        }

        if ($this->tokenOlderThanPassword($payload['iat'] ?? null, $user->passwordChangedAt)) {
            return $this->clearAuthCookie($request, response()->json(['message' => '密码已修改，请重新登录'], 401));
        }

        return response()->json([
            'user' => [
                'id' => (int) $user->id,
                'username' => $user->username,
                'name' => $user->name ?: $user->username,
                'grade' => $user->grade,
                'class' => $user->class,
                'role' => RoleHelper::normalizeRoleOrDefault($user->role, 'USER'),
                'requirePasswordChange' => (bool) $user->forcePasswordChange || $user->passwordChangedAt === null,
                'has2FA' => false,
                'avatar' => AvatarHelper::resolvePreferredAvatar($user->avatar, $user->username, $user->email),
            ],
            'valid' => true,
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        return $this->clearAuthCookie($request, response()->json([
            'success' => true,
            'message' => '已退出登录',
        ]));
    }

    private function attachAuthCookie(Request $request, JsonResponse $response, string $token): JsonResponse
    {
        $secure = $request->isSecure() || strtolower((string) $request->header('x-forwarded-proto', '')) === 'https';

        $response->headers->setCookie(
            cookie(
                'auth-token',
                $token,
                60 * 24 * 7,
                '/',
                config('session.domain'),
                $secure,
                true,
                false,
                'lax'
            )
        );

        return $response;
    }

    private function clearAuthCookie(Request $request, JsonResponse $response): JsonResponse
    {
        $secure = $request->isSecure() || strtolower((string) $request->header('x-forwarded-proto', '')) === 'https';

        $response->headers->setCookie(
            cookie(
                'auth-token',
                '',
                -60,
                '/',
                config('session.domain'),
                $secure,
                true,
                false,
                'lax'
            )
        );

        return $response;
    }

    private function tokenOlderThanPassword(mixed $issuedAt, mixed $passwordChangedAt): bool
    {
        if ($issuedAt === null || $passwordChangedAt === null) {
            return false;
        }

        $issuedAtTs = is_numeric($issuedAt) ? (int) $issuedAt : 0;
        if ($issuedAtTs <= 0) {
            return false;
        }

        $changedTs = strtotime((string) $passwordChangedAt);
        if ($changedTs === false) {
            return false;
        }

        return $issuedAtTs < $changedTs;
    }

    private function extractClientIp(Request $request): string
    {
        $forwarded = trim((string) $request->header('x-forwarded-for', ''));
        if ($forwarded !== '') {
            $parts = array_map('trim', explode(',', $forwarded));
            if ($parts !== [] && $parts[0] !== '') {
                return substr($parts[0], 0, 255);
            }
        }

        return substr((string) $request->ip(), 0, 255);
    }
}
