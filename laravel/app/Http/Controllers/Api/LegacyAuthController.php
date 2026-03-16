<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\JwtToken;
use App\Support\RoleHelper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LegacyAuthController extends Controller
{
    public function loginGet(): JsonResponse
    {
        return response()->json([
            'message' => 'Method Not Allowed',
        ], 405);
    }

    public function registerVerifyGet(Request $request): RedirectResponse
    {
        $token = trim((string) $request->query('token', ''));
        if ($token === '') {
            return redirect($this->appUrl('/login?activation=invalid'));
        }

        return redirect($this->appUrl('/api/auth/register/activate?token='.rawurlencode($token)));
    }

    public function registerActivate(Request $request): RedirectResponse
    {
        $token = trim((string) $request->query('token', ''));
        if ($token === '') {
            return redirect($this->appUrl('/login?activation=invalid'));
        }

        try {
            $payload = $this->decodeActivationToken($token);
            $userId = (int) ($payload['userId'] ?? 0);
            $email = strtolower(trim((string) ($payload['email'] ?? '')));
            if ($userId <= 0 || $email === '') {
                return redirect($this->appUrl('/login?activation=invalid'));
            }

            $user = DB::table('User')->where('id', $userId)->where('email', $email)->first();
            if ($user === null) {
                return redirect($this->appUrl('/login?activation=invalid'));
            }
            if ((string) ($user->status ?? 'active') !== 'active') {
                return redirect($this->appUrl('/login?activation=blocked'));
            }
            if (!($user->emailVerified ?? false)) {
                DB::table('User')->where('id', $user->id)->update([
                    'emailVerified' => true,
                    'updatedAt' => now(),
                ]);
                return redirect($this->appUrl('/login?activation=success'));
            }

            return redirect($this->appUrl('/login?activation=already'));
        } catch (\Throwable $exception) {
            $message = $exception->getMessage();
            if (str_contains($message, 'EXPIRED')) {
                return redirect($this->appUrl('/login?activation=expired'));
            }

            return redirect($this->appUrl('/login?activation=invalid'));
        }
    }

    public function registerVerifyPost(Request $request): JsonResponse
    {
        $token = trim((string) $request->input('token', ''));
        if ($token === '') {
            return response()->json([
                'message' => '缺少激活令牌，请通过邮件中的激活链接完成验证',
            ], 400);
        }

        try {
            $payload = $this->decodeActivationToken($token);
            $user = DB::table('User')
                ->where('id', (int) ($payload['userId'] ?? 0))
                ->where('email', strtolower((string) ($payload['email'] ?? '')))
                ->first();
            if ($user === null) {
                return response()->json(['message' => '账号不存在'], 404);
            }
            if ((string) ($user->status ?? 'active') !== 'active') {
                return response()->json(['message' => '账号当前不可用，请联系管理员处理'], 403);
            }
            if (!($user->emailVerified ?? false)) {
                DB::table('User')->where('id', $user->id)->update([
                    'emailVerified' => true,
                    'lastLogin' => now(),
                    'lastLoginIp' => substr((string) $request->ip(), 0, 255),
                    'updatedAt' => now(),
                ]);
            }

            $role = RoleHelper::normalizeRoleOrDefault((string) $user->role, 'USER');
            $jwt = JwtToken::generate((int) $user->id, $role);

            $response = response()->json([
                'success' => true,
                'message' => '账号激活成功，已自动登录',
                'user' => [
                    'id' => (int) $user->id,
                    'username' => $user->username,
                    'name' => $user->name ?: $user->username,
                    'grade' => $user->grade,
                    'class' => $user->class,
                    'role' => $role,
                    'avatar' => $user->avatar,
                    'needsPasswordChange' => false,
                ],
            ]);

            $response->headers->setCookie(cookie(
                'auth-token',
                $jwt,
                60 * 24 * 7,
                '/',
                config('session.domain'),
                $request->isSecure() || strtolower((string) $request->header('x-forwarded-proto', '')) === 'https',
                true,
                false,
                'lax'
            ));

            return $response;
        } catch (\Throwable $exception) {
            $message = $exception->getMessage();
            if (str_contains($message, 'EXPIRED')) {
                return response()->json(['message' => '激活链接已过期'], 400);
            }

            return response()->json(['message' => '激活链接无效'], 400);
        }
    }

    private function appUrl(string $path): string
    {
        $base = trim((string) env('APP_BASE_PATH', '/rareapp'));
        $base = $base === '' || $base === '/' ? '' : '/'.trim($base, '/');
        $path = '/'.ltrim($path, '/');

        return $base.$path;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeActivationToken(string $token): array
    {
        $decoded = json_decode(base64_decode(strtr($token, '-_', '+/'), true) ?: '', true);
        if (is_array($decoded) && isset($decoded['userId'], $decoded['email'], $decoded['exp'])) {
            if ((int) $decoded['exp'] < time()) {
                throw new \RuntimeException('TOKEN_EXPIRED');
            }

            return $decoded;
        }

        // Fallback: legacy token is "<userId>:<email>:<hash>" encrypted by app key.
        try {
            $plain = decrypt($token);
            if (!is_string($plain)) {
                throw new \RuntimeException('TOKEN_INVALID');
            }
            $parts = explode(':', $plain);
            if (count($parts) < 3) {
                throw new \RuntimeException('TOKEN_INVALID');
            }
            $userId = (int) $parts[0];
            $email = strtolower(trim($parts[1]));
            $exp = (int) $parts[2];
            if ($userId <= 0 || $email === '' || $exp <= 0) {
                throw new \RuntimeException('TOKEN_INVALID');
            }
            if ($exp < time()) {
                throw new \RuntimeException('TOKEN_EXPIRED');
            }

            return ['userId' => $userId, 'email' => $email, 'exp' => $exp];
        } catch (\Throwable) {
            throw new \RuntimeException('TOKEN_INVALID');
        }
    }
}
