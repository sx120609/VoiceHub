<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\JwtToken;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateJwt
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken() ?: (string) $request->cookie('auth-token', '');
        if ($token === '') {
            return $this->unauthorized('未提供认证令牌');
        }

        try {
            $payload = JwtToken::verify($token);
        } catch (\RuntimeException $exception) {
            if ($exception->getMessage() === 'JWT_SECRET_NOT_CONFIGURED') {
                return response()->json(['message' => '服务器配置错误'], 500);
            }

            return $this->unauthorized('令牌无效或已过期', true, $request);
        }

        $userId = (int) ($payload['userId'] ?? 0);
        if ($userId <= 0) {
            return $this->unauthorized('令牌无效或已过期', true, $request);
        }

        $user = User::query()->find($userId);
        if ($user === null) {
            return $this->unauthorized('用户不存在', true, $request);
        }

        if ($this->tokenOlderThanPassword($payload['iat'] ?? null, $user->passwordChangedAt)) {
            return $this->unauthorized('密码已修改，请重新登录', true, $request);
        }

        $request->setUserResolver(static fn (): User => $user);
        $request->attributes->set('auth_payload', $payload);

        return $next($request);
    }

    private function unauthorized(string $message, bool $clearCookie = false, ?Request $request = null): JsonResponse
    {
        $response = response()->json(['message' => $message], 401);
        if ($clearCookie) {
            $response->headers->setCookie(
                cookie(
                    'auth-token',
                    '',
                    -60,
                    '/',
                    config('session.domain'),
                    $request?->isSecure() ?? false,
                    true,
                    false,
                    'lax'
                )
            );
        }

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
}
