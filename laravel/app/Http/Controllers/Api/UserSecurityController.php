<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\TotpHelper;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class UserSecurityController extends Controller
{
    public function generate2fa(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $exists = DB::table('UserIdentity')
            ->where('userId', $user->id)
            ->where('provider', 'totp')
            ->exists();

        if ($exists) {
            return response()->json(['message' => '已开启双重认证'], 400);
        }

        $secret = TotpHelper::generateSecret(32);
        $otpauth = TotpHelper::buildOtpAuthUri((string) $user->username, $secret, 'VoiceHub');
        $qrCode = 'https://api.qrserver.com/v1/create-qr-code/?size=256x256&data='.rawurlencode($otpauth);

        return response()->json([
            'secret' => $secret,
            'qrCode' => $qrCode,
            'otpauth' => $otpauth,
        ]);
    }

    public function enable2fa(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $token = trim((string) $request->input('token', ''));
        $secret = strtoupper(trim((string) $request->input('secret', '')));

        if ($token === '' || $secret === '') {
            return response()->json(['message' => '缺少验证码或密钥'], 400);
        }
        if (!TotpHelper::verify($secret, $token, 1)) {
            return response()->json(['message' => '验证码错误'], 400);
        }

        $existing = DB::table('UserIdentity')
            ->where('userId', $user->id)
            ->where('provider', 'totp')
            ->first();

        if ($existing !== null) {
            DB::table('UserIdentity')
                ->where('id', $existing->id)
                ->update([
                    'providerUserId' => $secret,
                    'providerUsername' => json_encode(['enabledAt' => now()->toISOString()]),
                ]);
        } else {
            DB::table('UserIdentity')->insert([
                'userId' => $user->id,
                'provider' => 'totp',
                'providerUserId' => $secret,
                'providerUsername' => json_encode(['enabledAt' => now()->toISOString()]),
                'createdAt' => now(),
            ]);
        }

        return response()->json(['success' => true]);
    }

    public function disable2fa(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $password = (string) $request->input('password', '');
        if ($password === '') {
            return response()->json(['message' => '请输入密码'], 400);
        }

        $current = DB::table('User')->select(['password'])->where('id', $user->id)->first();
        if ($current === null) {
            return response()->json(['message' => '用户不存在'], 404);
        }
        if (!Hash::check($password, (string) $current->password)) {
            return response()->json(['message' => '密码错误'], 403);
        }

        DB::table('UserIdentity')
            ->where('userId', $user->id)
            ->where('provider', 'totp')
            ->delete();

        return response()->json(['success' => true]);
    }

    public function yearReview(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $year = (int) $request->query('year', Carbon::now('Asia/Shanghai')->year);
        if ($year < 2020 || $year > 2100) {
            $year = Carbon::now('Asia/Shanghai')->year;
        }

        $start = Carbon::create($year, 1, 1, 0, 0, 0, 'Asia/Shanghai');
        $end = $start->copy()->addYear();

        try {
            $totalRequests = (int) DB::table('Song')
                ->where('requesterId', $user->id)
                ->whereBetween('createdAt', [$start, $end])
                ->count();

            $playedRequests = (int) DB::table('Song')
                ->where('requesterId', $user->id)
                ->where('played', true)
                ->whereBetween('createdAt', [$start, $end])
                ->count();

            $topArtist = DB::table('Song')
                ->select(['artist', DB::raw('COUNT(id) as count')])
                ->where('requesterId', $user->id)
                ->whereBetween('createdAt', [$start, $end])
                ->groupBy('artist')
                ->orderByDesc(DB::raw('COUNT(id)'))
                ->value('artist');

            $topPlatform = DB::table('Song')
                ->select(['musicPlatform', DB::raw('COUNT(id) as count')])
                ->where('requesterId', $user->id)
                ->whereBetween('createdAt', [$start, $end])
                ->groupBy('musicPlatform')
                ->orderByDesc(DB::raw('COUNT(id)'))
                ->value('musicPlatform');

            $totalVotes = (int) DB::table('Vote')
                ->where('userId', $user->id)
                ->whereBetween('createdAt', [$start, $end])
                ->count();

            $activeMonth = null;
            try {
                $monthRow = DB::table('Song')
                    ->select([DB::raw('EXTRACT(MONTH FROM "createdAt") as month'), DB::raw('COUNT(id) as count')])
                    ->where('requesterId', $user->id)
                    ->whereBetween('createdAt', [$start, $end])
                    ->groupBy(DB::raw('EXTRACT(MONTH FROM "createdAt")'))
                    ->orderByDesc(DB::raw('COUNT(id)'))
                    ->first();
                if ($monthRow !== null) {
                    $activeMonth = (int) $monthRow->month;
                }
            } catch (\Throwable) {
                $activeMonth = null;
            }

            $firstSong = DB::table('Song')
                ->where('requesterId', $user->id)
                ->whereBetween('createdAt', [$start, $end])
                ->orderBy('createdAt')
                ->first(['title', 'artist', 'cover', 'createdAt']);

            return response()->json([
                'success' => true,
                'data' => [
                    'year' => $year,
                    'totalRequests' => $totalRequests,
                    'playedRequests' => $playedRequests,
                    'topArtist' => $topArtist ?: null,
                    'topPlatform' => $topPlatform ?: null,
                    'totalVotes' => $totalVotes,
                    'activeMonth' => $activeMonth,
                    'firstSong' => $firstSong ? [
                        'title' => $firstSong->title,
                        'artist' => $firstSong->artist,
                        'cover' => $firstSong->cover,
                        'createdAt' => $firstSong->createdAt,
                    ] : null,
                ],
            ]);
        } catch (\Throwable) {
            return response()->json(['message' => '获取年度总结失败'], 500);
        }
    }
}
