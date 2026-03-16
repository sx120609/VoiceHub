<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class SocialBindingController extends Controller
{
    /**
     * @var array<string, array{code: string, userId: int, expiresAt: int}>
     */
    private static array $meowCodes = [];

    /**
     * @var array<string, array{code: string, userId: int, expiresAt: int}>
     */
    private static array $emailCodes = [];

    public function meowBind(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $action = (string) $request->input('action', '');
        $meowId = trim((string) $request->input('meowId', ''));
        $verificationCode = trim((string) $request->input('verificationCode', ''));

        if ($action === 'verify_and_bind') {
            if ($meowId === '' || $verificationCode === '') {
                return response()->json(['message' => '参数不完整'], 400);
            }
            $stored = self::$meowCodes[$meowId] ?? null;
            if ($stored === null || $stored['userId'] !== (int) $user->id) {
                return response()->json(['message' => '验证码不匹配'], 400);
            }
            if (time() > $stored['expiresAt']) {
                unset(self::$meowCodes[$meowId]);

                return response()->json(['message' => '验证码已过期，请重新发送'], 400);
            }
            if ($stored['code'] !== $verificationCode) {
                return response()->json(['message' => '验证码错误'], 400);
            }

            DB::table('User')
                ->where('id', $user->id)
                ->update([
                    'meowNickname' => $meowId,
                    'meowBoundAt' => now(),
                    'updatedAt' => now(),
                ]);

            unset(self::$meowCodes[$meowId]);

            return response()->json([
                'success' => true,
                'message' => 'MeoW 账号绑定成功！',
            ]);
        }

        if ($action !== 'send_verification') {
            return response()->json(['message' => '无效的操作'], 400);
        }

        if ($meowId === '') {
            return response()->json(['message' => '请输入 MeoW 用户 ID'], 400);
        }
        if (str_contains($meowId, '/')) {
            return response()->json(['message' => '用户 ID 不能包含斜杠'], 400);
        }

        $code = (string) random_int(100000, 999999);
        self::$meowCodes[$meowId] = [
            'code' => $code,
            'userId' => (int) $user->id,
            'expiresAt' => time() + 300,
        ];

        $title = 'VoiceHub 账号绑定';
        $message = sprintf('VoiceHub 账号绑定验证码：%s（5分钟内有效）', $code);
        $url = 'https://api.chuckfang.com/'.rawurlencode($meowId).'/'.rawurlencode($title).'/'.rawurlencode($message);

        try {
            $response = Http::timeout(8)->withHeaders([
                'User-Agent' => 'VoiceHub/1.0',
            ])->get($url);

            if (!$response->ok()) {
                unset(self::$meowCodes[$meowId]);

                return response()->json(['message' => '发送验证码失败，请稍后重试'], 500);
            }
        } catch (\Throwable) {
            unset(self::$meowCodes[$meowId]);

            return response()->json(['message' => '发送验证码失败，请稍后重试'], 500);
        }

        return response()->json([
            'success' => true,
            'message' => '验证码已发送到您的 MeoW，请查收并输入验证码完成绑定',
            'requiresVerification' => true,
        ]);
    }

    public function meowUnbind(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $row = DB::table('User')->select(['meowNickname'])->where('id', $user->id)->first();
        if (empty($row?->meowNickname)) {
            return response()->json(['message' => '您尚未绑定 MeoW 账号'], 400);
        }

        DB::table('User')
            ->where('id', $user->id)
            ->update([
                'meowNickname' => null,
                'meowBoundAt' => null,
                'updatedAt' => now(),
            ]);

        return response()->json([
            'success' => true,
            'message' => 'MeoW 账号已成功解绑',
        ]);
    }

    public function socialAccountsMeowBind(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $nickname = trim((string) $request->input('nickname', ''));
        if ($nickname === '') {
            return response()->json(['message' => '昵称不能为空'], 400);
        }
        if (str_contains($nickname, '/')) {
            return response()->json(['message' => '昵称不能包含斜杠'], 400);
        }

        DB::table('User')->where('id', $user->id)->update([
            'meowNickname' => $nickname,
            'meowBoundAt' => now(),
            'updatedAt' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'MeoW 账号绑定成功',
        ]);
    }

    public function socialAccountsMeowDelete(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        DB::table('User')->where('id', $user->id)->update([
            'meowNickname' => null,
            'meowBoundAt' => null,
            'updatedAt' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'MeoW 账号解绑成功',
        ]);
    }

    public function meowSendVerification(Request $request): JsonResponse
    {
        $nickname = trim((string) $request->input('nickname', ''));
        $verificationCode = trim((string) $request->input('verificationCode', ''));
        if ($nickname === '' || $verificationCode === '') {
            return response()->json(['message' => '昵称和验证码不能为空'], 400);
        }
        if (str_contains($nickname, '/')) {
            return response()->json(['message' => '昵称不能包含斜杠'], 400);
        }

        $title = 'VoiceHub 账号绑定';
        $message = 'VoiceHub 账号绑定验证码：'.$verificationCode;
        $url = 'https://api.chuckfang.com/'.rawurlencode($nickname).'/'.rawurlencode($title).'/'.rawurlencode($message);

        try {
            $response = Http::timeout(8)->withHeaders([
                'User-Agent' => 'VoiceHub/1.0',
            ])->get($url);

            if (!$response->ok()) {
                return response()->json(['message' => '发送验证码失败，请检查昵称是否正确'], 500);
            }

            $json = $response->json();
            if (is_array($json) && (int) ($json['status'] ?? 500) !== 200) {
                return response()->json(['message' => (string) ($json['message'] ?? '发送验证码失败')], 500);
            }

            return response()->json([
                'success' => true,
                'message' => '验证码发送成功',
            ]);
        } catch (\Throwable) {
            return response()->json(['message' => '发送验证码失败，请稍后重试'], 500);
        }
    }

    public function meowTest(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $row = DB::table('User')->select(['username', 'meowNickname'])->where('id', $user->id)->first();
        if ($row === null) {
            return response()->json(['message' => '用户不存在'], 404);
        }
        if (empty($row->meowNickname)) {
            return response()->json(['message' => '尚未绑定 MeoW 账号'], 400);
        }

        $title = 'VoiceHub 测试通知';
        $message = '这是来自 VoiceHub 的测试通知！您的账号 '.$row->username.' 已成功绑定 MeoW。';
        $url = 'https://api.chuckfang.com/'.rawurlencode((string) $row->meowNickname).'/'.rawurlencode($title).'/'.rawurlencode($message);

        try {
            $response = Http::timeout(8)->withHeaders([
                'User-Agent' => 'VoiceHub/1.0',
            ])->get($url);
            if (!$response->ok()) {
                return response()->json(['message' => '发送测试通知失败'], 500);
            }
            $json = $response->json();
            if (is_array($json) && (int) ($json['status'] ?? 500) !== 200) {
                return response()->json(['message' => (string) ($json['message'] ?? '发送测试通知失败')], 500);
            }

            return response()->json([
                'success' => true,
                'message' => '测试通知发送成功',
            ]);
        } catch (\Throwable) {
            return response()->json(['message' => '发送测试通知失败，请稍后重试'], 500);
        }
    }

    public function emailBind(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $email = strtolower(trim((string) $request->input('email', '')));
        if (!preg_match('/^[1-9]\d{4,10}@qq\.com$/', $email)) {
            return response()->json(['message' => '仅支持绑定 QQ 邮箱'], 400);
        }

        $exists = DB::table('User')->where('email', $email)->where('id', '<>', $user->id)->exists();
        if ($exists) {
            return response()->json(['message' => '该邮箱已被其他用户绑定'], 400);
        }

        DB::table('User')
            ->where('id', $user->id)
            ->update([
                'email' => $email,
                'emailVerified' => true,
                'updatedAt' => now(),
            ]);

        return response()->json([
            'success' => true,
            'message' => 'QQ邮箱绑定成功',
        ]);
    }

    public function emailSendCode(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $email = strtolower(trim((string) $request->input('email', '')));
        if (!preg_match('/^[1-9]\d{4,10}@qq\.com$/', $email)) {
            return response()->json(['message' => '仅支持 QQ 邮箱'], 400);
        }

        $current = DB::table('User')->select(['email'])->where('id', $user->id)->first();
        if (strtolower((string) ($current->email ?? '')) !== $email) {
            return response()->json(['message' => '邮箱不匹配，请先绑定当前QQ邮箱'], 400);
        }

        $code = (string) random_int(100000, 999999);
        self::$emailCodes[$email] = [
            'code' => $code,
            'userId' => (int) $user->id,
            'expiresAt' => time() + 300,
        ];

        // 这里先返回成功，邮件发送后续可接 SMTP 模块完善
        return response()->json([
            'success' => true,
            'message' => '验证码已生成（当前为兼容模式）',
        ]);
    }

    public function emailVerifyCode(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $email = strtolower(trim((string) $request->input('email', '')));
        if (!preg_match('/^[1-9]\d{4,10}@qq\.com$/', $email)) {
            return response()->json(['message' => '仅支持 QQ 邮箱'], 400);
        }

        // 兼容旧逻辑：若已绑定同邮箱，直接验证成功
        $current = DB::table('User')->select(['email'])->where('id', $user->id)->first();
        if (strtolower((string) ($current->email ?? '')) !== $email) {
            return response()->json(['message' => '邮箱不匹配，请先绑定当前QQ邮箱'], 400);
        }

        $providedCode = trim((string) $request->input('code', ''));
        $stored = self::$emailCodes[$email] ?? null;
        if ($stored !== null) {
            if ($stored['userId'] !== (int) $user->id) {
                return response()->json(['message' => '验证码不匹配'], 400);
            }
            if (time() > $stored['expiresAt']) {
                unset(self::$emailCodes[$email]);

                return response()->json(['message' => '验证码已过期，请重新发送'], 400);
            }
            if ($providedCode !== '' && $stored['code'] !== $providedCode) {
                return response()->json(['message' => '验证码错误'], 400);
            }
        }

        DB::table('User')
            ->where('id', $user->id)
            ->update([
                'emailVerified' => true,
                'updatedAt' => now(),
            ]);

        unset(self::$emailCodes[$email]);

        return response()->json([
            'success' => true,
            'message' => 'QQ邮箱已验证',
        ]);
    }

    public function emailResendVerification(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $current = DB::table('User')
            ->select(['email', 'emailVerified'])
            ->where('id', $user->id)
            ->first();

        $email = strtolower((string) ($current->email ?? ''));
        if ($email === '') {
            return response()->json(['message' => '请先绑定QQ邮箱'], 400);
        }
        if (!preg_match('/^[1-9]\d{4,10}@qq\.com$/', $email)) {
            return response()->json(['message' => '当前邮箱不是QQ邮箱，请先更换为QQ邮箱'], 400);
        }

        if (!($current->emailVerified ?? false)) {
            DB::table('User')
                ->where('id', $user->id)
                ->update([
                    'emailVerified' => true,
                    'updatedAt' => now(),
                ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'QQ邮箱已自动验证，无需验证码',
        ]);
    }

    public function emailUnbind(): JsonResponse
    {
        return response()->json([
            'message' => '当前系统已启用强制QQ邮箱，不能解绑邮箱',
        ], 403);
    }
}
