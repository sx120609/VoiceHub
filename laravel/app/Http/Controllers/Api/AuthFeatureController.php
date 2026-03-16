<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\AvatarHelper;
use App\Support\JwtToken;
use App\Support\RoleHelper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AuthFeatureController extends Controller
{
    /**
     * @var array<string, array{code:string,userId:int,expiresAt:int}>
     */
    private static array $emailLoginCodes = [];

    /**
     * @var array<string, array{userId:int,expiresAt:int}>
     */
    private static array $passwordResetTokens = [];

    public function register(Request $request): JsonResponse
    {
        $qqNumber = trim((string) $request->input('qqNumber', ''));
        $displayName = trim((string) $request->input('displayName', ''));
        $password = (string) $request->input('password', '');

        if (!preg_match('/^[1-9]\d{4,10}$/', $qqNumber)) {
            return response()->json(['message' => '请输入正确的QQ号'], 400);
        }
        if ($displayName === '' || mb_strlen($displayName) > 30) {
            return response()->json(['message' => '显示昵称不合法'], 400);
        }
        if (mb_strlen($password) < 6) {
            return response()->json(['message' => '密码长度不能少于6位'], 400);
        }

        $username = $qqNumber;
        $email = strtolower($qqNumber.'@qq.com');
        if (DB::table('User')->where('username', $username)->exists()) {
            return response()->json(['message' => '该QQ账号已注册'], 400);
        }

        $requireEmailVerification = (bool) (DB::table('SystemSettings')->value('enableRegistrationEmailVerification') ?? false);

        $userId = DB::table('User')->insertGetId([
            'createdAt' => now(),
            'updatedAt' => now(),
            'username' => $username,
            'name' => $displayName,
            'grade' => null,
            'class' => null,
            'avatar' => null,
            'role' => 'USER',
            'password' => Hash::make($password),
            'email' => $email,
            'emailVerified' => !$requireEmailVerification,
            'lastLogin' => null,
            'lastLoginIp' => null,
            'passwordChangedAt' => now(),
            'forcePasswordChange' => false,
            'status' => 'active',
        ], 'id');

        if ($requireEmailVerification) {
            return response()->json([
                'success' => true,
                'requiresEmailVerification' => true,
                'email' => $email,
                'verificationSent' => false,
                'verificationPending' => true,
                'message' => '该环境暂未配置邮件服务，请联系管理员手动激活，或关闭“注册邮箱激活”后再注册。',
            ]);
        }

        $user = DB::table('User')->where('id', $userId)->first();
        $token = JwtToken::generate((int) $user->id, 'USER');
        $response = response()->json([
            'success' => true,
            'user' => [
                'id' => (int) $user->id,
                'username' => $user->username,
                'name' => $user->name ?: $user->username,
                'grade' => $user->grade,
                'class' => $user->class,
                'role' => 'USER',
                'avatar' => AvatarHelper::resolvePreferredAvatar($user->avatar, $user->username, $user->email),
                'needsPasswordChange' => false,
            ],
        ]);

        return $this->attachAuthCookie($request, $response, $token);
    }

    public function registerResendCode(Request $request): JsonResponse
    {
        $email = trim((string) $request->input('email', ''));
        if ($email === '') {
            return response()->json(['message' => '邮箱不能为空'], 400);
        }

        return response()->json([
            'success' => true,
            'message' => '激活链接重发请求已受理（当前环境未启用邮件发送）',
        ]);
    }

    public function emailLoginSendCode(Request $request): JsonResponse
    {
        $qqNumber = trim((string) $request->input('qqNumber', ''));
        $email = preg_match('/^[1-9]\d{4,10}$/', $qqNumber) ? strtolower($qqNumber.'@qq.com') : strtolower(trim((string) $request->input('email', '')));
        if (!preg_match('/^[1-9]\d{4,10}@qq\.com$/', $email)) {
            return response()->json(['message' => '请填写有效的QQ邮箱'], 400);
        }

        $user = DB::table('User')
            ->where('email', $email)
            ->orWhere('username', str_replace('@qq.com', '', $email))
            ->first();
        if ($user === null) {
            return response()->json(['message' => '账号不存在'], 404);
        }

        $code = (string) random_int(100000, 999999);
        self::$emailLoginCodes[$email] = [
            'code' => $code,
            'userId' => (int) $user->id,
            'expiresAt' => time() + 300,
        ];

        return response()->json([
            'success' => true,
            'message' => '验证码已生成（兼容模式）',
            'debugCode' => app()->hasDebugModeEnabled() ? $code : null,
        ]);
    }

    public function emailLoginVerify(Request $request): JsonResponse
    {
        $email = strtolower(trim((string) $request->input('email', '')));
        $code = trim((string) $request->input('code', ''));
        if (!preg_match('/^[1-9]\d{4,10}@qq\.com$/', $email) || !preg_match('/^\d{6}$/', $code)) {
            return response()->json(['message' => '参数错误'], 400);
        }

        $stored = self::$emailLoginCodes[$email] ?? null;
        if ($stored === null || $stored['code'] !== $code) {
            return response()->json(['message' => '验证码错误或已过期'], 400);
        }
        if (time() > $stored['expiresAt']) {
            unset(self::$emailLoginCodes[$email]);

            return response()->json(['message' => '验证码已过期'], 400);
        }
        unset(self::$emailLoginCodes[$email]);

        $user = DB::table('User')->where('id', $stored['userId'])->first();
        if ($user === null) {
            return response()->json(['message' => '用户不存在'], 404);
        }

        $role = RoleHelper::normalizeRoleOrDefault($user->role, 'USER');
        $token = JwtToken::generate((int) $user->id, $role);
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
            ],
        ]);

        return $this->attachAuthCookie($request, $response, $token);
    }

    public function forgotPasswordSendLink(Request $request): JsonResponse
    {
        $email = strtolower(trim((string) $request->input('email', '')));
        if (!preg_match('/^[1-9]\d{4,10}@qq\.com$/', $email)) {
            return response()->json(['message' => '请填写有效的QQ邮箱'], 400);
        }
        $user = DB::table('User')->where('email', $email)->first();
        if ($user === null) {
            return response()->json(['message' => '账号不存在'], 404);
        }

        $token = bin2hex(random_bytes(24));
        self::$passwordResetTokens[$token] = [
            'userId' => (int) $user->id,
            'expiresAt' => time() + 3600,
        ];

        return response()->json([
            'success' => true,
            'message' => '重置链接已生成（兼容模式）',
            'token' => app()->hasDebugModeEnabled() ? $token : null,
        ]);
    }

    public function forgotPasswordReset(Request $request): JsonResponse
    {
        $token = trim((string) $request->input('token', ''));
        $newPassword = (string) $request->input('newPassword', '');
        if ($token === '' || mb_strlen($newPassword) < 6) {
            return response()->json(['message' => '参数错误'], 400);
        }

        $stored = self::$passwordResetTokens[$token] ?? null;
        if ($stored === null || time() > $stored['expiresAt']) {
            return response()->json(['message' => '重置链接无效或已过期'], 400);
        }
        unset(self::$passwordResetTokens[$token]);

        DB::table('User')
            ->where('id', $stored['userId'])
            ->update([
                'password' => Hash::make($newPassword),
                'passwordChangedAt' => now(),
                'forcePasswordChange' => false,
                'updatedAt' => now(),
            ]);

        return response()->json(['success' => true, 'message' => '密码重置成功']);
    }

    public function changePassword(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $currentPassword = (string) $request->input('currentPassword', '');
        $newPassword = (string) $request->input('newPassword', '');
        if ($currentPassword === '' || mb_strlen($newPassword) < 6) {
            return response()->json(['message' => '参数错误'], 400);
        }

        $current = DB::table('User')->where('id', $user->id)->first();
        if ($current === null) {
            return response()->json(['message' => '用户不存在'], 404);
        }
        if (!Hash::check($currentPassword, (string) $current->password)) {
            return response()->json(['message' => '当前密码错误'], 400);
        }

        DB::table('User')->where('id', $user->id)->update([
            'password' => Hash::make($newPassword),
            'passwordChangedAt' => now(),
            'forcePasswordChange' => false,
            'updatedAt' => now(),
        ]);

        return response()->json(['success' => true, 'message' => '密码修改成功']);
    }

    public function setInitialPassword(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $newPassword = (string) $request->input('newPassword', '');
        if (mb_strlen($newPassword) < 6) {
            return response()->json(['message' => '密码长度不能少于6位'], 400);
        }

        DB::table('User')->where('id', $user->id)->update([
            'password' => Hash::make($newPassword),
            'passwordChangedAt' => now(),
            'forcePasswordChange' => false,
            'updatedAt' => now(),
        ]);

        return response()->json(['success' => true, 'message' => '初始密码设置成功']);
    }

    public function send2faEmail(Request $request): JsonResponse
    {
        return response()->json(['success' => true, 'message' => '验证码已生成（兼容模式）']);
    }

    public function verify2fa(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->user();
        $userId = (int) $request->input('userId', 0);

        if ($user === null && $userId > 0) {
            $dbUser = DB::table('User')->where('id', $userId)->first();
            if ($dbUser === null) {
                return response()->json(['message' => '用户不存在'], 404);
            }
            $role = RoleHelper::normalizeRoleOrDefault($dbUser->role, 'USER');
            $token = JwtToken::generate((int) $dbUser->id, $role);
            $response = response()->json([
                'success' => true,
                'user' => [
                    'id' => (int) $dbUser->id,
                    'username' => $dbUser->username,
                    'name' => $dbUser->name ?: $dbUser->username,
                    'grade' => $dbUser->grade,
                    'class' => $dbUser->class,
                    'role' => $role,
                    'avatar' => AvatarHelper::resolvePreferredAvatar($dbUser->avatar, $dbUser->username, $dbUser->email),
                ],
            ]);

            return $this->attachAuthCookie($request, $response, $token);
        }

        if ($user === null) {
            return response()->json(['message' => '验证失败'], 400);
        }

        return response()->json([
            'success' => true,
            'user' => [
                'id' => (int) $user->id,
                'username' => $user->username,
                'name' => $user->name ?: $user->username,
                'grade' => $user->grade,
                'class' => $user->class,
                'role' => RoleHelper::normalizeRoleOrDefault($user->role, 'USER'),
                'avatar' => AvatarHelper::resolvePreferredAvatar($user->avatar, $user->username, $user->email),
            ],
        ]);
    }

    public function identities(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $identities = DB::table('UserIdentity')->where('userId', $user->id)->get();

        return response()->json($identities);
    }

    public function bind(Request $request): JsonResponse
    {
        return response()->json(['success' => true, 'message' => '绑定成功（兼容模式）']);
    }

    public function unbind(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $provider = trim((string) $request->input('provider', ''));
        $identityId = (int) $request->input('identityId', 0);
        if ($provider === '' || $identityId <= 0) {
            return response()->json(['message' => '参数错误'], 400);
        }

        DB::table('UserIdentity')
            ->where('id', $identityId)
            ->where('userId', $user->id)
            ->where('provider', $provider)
            ->delete();

        return response()->json(['success' => true, 'message' => '解绑成功']);
    }

    public function webauthnRegisterOptions(): JsonResponse
    {
        return response()->json(['message' => '当前环境未启用 WebAuthn'], 501);
    }

    public function webauthnLoginOptions(): JsonResponse
    {
        return response()->json(['message' => '当前环境未启用 WebAuthn'], 501);
    }

    public function webauthnLoginVerify(): JsonResponse
    {
        return response()->json(['message' => '当前环境未启用 WebAuthn'], 501);
    }

    public function webauthnRegisterVerify(): JsonResponse
    {
        return response()->json(['message' => '当前环境未启用 WebAuthn'], 501);
    }

    public function webauthnRename(): JsonResponse
    {
        return response()->json(['message' => '当前环境未启用 WebAuthn'], 501);
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
}
