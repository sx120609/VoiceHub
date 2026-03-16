<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class PublicController extends Controller
{
    public function healthz(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'status' => 'ok',
            'timestamp' => Carbon::now()->toISOString(),
        ]);
    }

    public function siteConfig(): JsonResponse
    {
        $settings = DB::table('SystemSettings')->first();
        if ($settings === null) {
            $id = DB::table('SystemSettings')->insertGetId([
                'createdAt' => now(),
                'updatedAt' => now(),
                'enablePlayTimeSelection' => false,
                'siteTitle' => 'VoiceHub',
                'siteLogoUrl' => '/images/logo.png',
                'schoolLogoHomeUrl' => null,
                'schoolLogoPrintUrl' => null,
                'siteDescription' => '校园广播站点歌系统 - 让你的声音被听见',
                'submissionGuidelines' => '请遵守校园规定，提交健康向上的歌曲。',
                'icpNumber' => null,
                'gonganNumber' => null,
                'enableSubmissionLimit' => false,
                'dailySubmissionLimit' => null,
                'weeklySubmissionLimit' => null,
                'monthlySubmissionLimit' => null,
                'showBlacklistKeywords' => false,
                'hideStudentInfo' => true,
                'smtpEnabled' => false,
                'enableRegistrationEmailVerification' => false,
                'enableReplayRequests' => false,
                'enableRequestTimeLimitation' => false,
                'forceBlockAllRequests' => false,
            ], 'id');
            $settings = DB::table('SystemSettings')->where('id', $id)->first();
        }

        $publicFields = [
            'siteTitle',
            'siteLogoUrl',
            'schoolLogoHomeUrl',
            'schoolLogoPrintUrl',
            'siteDescription',
            'submissionGuidelines',
            'icpNumber',
            'gonganNumber',
            'enablePlayTimeSelection',
            'enableSubmissionLimit',
            'dailySubmissionLimit',
            'weeklySubmissionLimit',
            'monthlySubmissionLimit',
            'showBlacklistKeywords',
            'hideStudentInfo',
            'enableReplayRequests',
            'enableRequestTimeLimitation',
            'forceBlockAllRequests',
            'smtpEnabled',
            'enableRegistrationEmailVerification',
        ];

        $result = [];
        foreach ($publicFields as $field) {
            $result[$field] = $settings->{$field} ?? null;
        }

        return response()->json($result);
    }

    public function playTimes(): JsonResponse
    {
        $settings = DB::table('SystemSettings')->first();
        $enabled = (bool) ($settings->enablePlayTimeSelection ?? false);

        $playTimes = [];
        if ($enabled) {
            $playTimes = DB::table('PlayTime')
                ->where('enabled', true)
                ->orderBy('startTime')
                ->get();
        }

        return response()->json([
            'enabled' => $enabled,
            'playTimes' => $playTimes,
        ]);
    }

    public function requestTimes(): JsonResponse
    {
        $settings = DB::table('SystemSettings')->first();
        $enabled = (bool) ($settings->enableRequestTimeLimitation ?? false);
        $forceBlockAllRequests = (bool) ($settings->forceBlockAllRequests ?? false);

        $hit = false;
        $accepted = 0;
        $expected = 0;

        if ($enabled) {
            $now = Carbon::now('Asia/Shanghai')->toDateTimeString();
            $matched = DB::table('RequestTime')
                ->where('enabled', true)
                ->where('startTime', '<=', $now)
                ->where('endTime', '>', $now)
                ->first();

            if ($matched !== null) {
                $hit = true;
                $accepted = (int) ($matched->accepted ?? 0);
                $expected = (int) ($matched->expected ?? 0);
            }
        } else {
            $hit = true;
        }

        if ($forceBlockAllRequests) {
            $hit = false;
            $enabled = true;
        }

        return response()->json([
            'hit' => $hit,
            'enabled' => $enabled,
            'accepted' => $accepted,
            'expected' => $expected,
        ]);
    }

    public function currentSemester(): JsonResponse
    {
        $currentSemester = DB::table('Semester')
            ->where('isActive', true)
            ->first();

        if ($currentSemester === null) {
            return response()->json([
                'name' => null,
                'message' => '未设置活跃学期',
            ]);
        }

        return response()->json($currentSemester);
    }

    public function semesterOptions(): JsonResponse
    {
        $list = DB::table('Semester')
            ->select(['id', 'name', 'isActive'])
            ->orderByDesc('createdAt')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $list,
        ]);
    }

    public function songsCount(Request $request): JsonResponse
    {
        $semester = trim((string) $request->query('semester', ''));
        $query = DB::table('Song');
        if ($semester !== '') {
            $query->where('semester', $semester);
        }

        return response()->json([
            'count' => (int) $query->count(),
        ]);
    }

    public function systemLocation(): JsonResponse
    {
        try {
            $response = Http::timeout(5)->get('http://ip-api.com/json/', [
                'fields' => 'status,message,country,countryCode,regionName,city',
            ]);

            if ($response->ok()) {
                $payload = $response->json();
                if (($payload['status'] ?? '') === 'success') {
                    return response()->json([
                        'success' => true,
                        'data' => [
                            'country' => $payload['country'] ?? 'Unknown',
                            'countryCode' => $payload['countryCode'] ?? 'XX',
                            'region' => $payload['regionName'] ?? 'Unknown',
                            'city' => $payload['city'] ?? 'Unknown',
                            'isInChina' => ($payload['countryCode'] ?? '') === 'CN',
                        ],
                    ]);
                }
            }
        } catch (\Throwable) {
            // fallback below
        }

        return response()->json([
            'success' => true,
            'data' => [
                'country' => 'Unknown',
                'countryCode' => 'XX',
                'region' => 'Unknown',
                'city' => 'Unknown',
                'isInChina' => false,
            ],
        ]);
    }

    public function bootstrapHome(Request $request, SongController $songsController): JsonResponse
    {
        $siteConfig = $this->siteConfig()->getData(true);
        $songs = $songsController->index($request)->getData(true);
        $publicSchedules = $songsController->publicSchedules($request)->getData(true);
        $songCount = $this->songsCount($request)->getData(true);
        $playTimes = $this->playTimes()->getData(true);
        $currentSemester = $this->currentSemester()->getData(true);

        return response()->json([
            'success' => true,
            'partial' => false,
            'errors' => [],
            'data' => [
                'siteConfig' => $siteConfig,
                'songs' => $songs,
                'publicSchedules' => $publicSchedules,
                'songCount' => $songCount,
                'playTimes' => $playTimes,
                'currentSemester' => $currentSemester,
            ],
        ]);
    }
}
