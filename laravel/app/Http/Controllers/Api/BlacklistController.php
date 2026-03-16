<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BlacklistController extends Controller
{
    public function check(Request $request): JsonResponse
    {
        $title = trim((string) $request->input('title', ''));
        $artist = trim((string) $request->input('artist', ''));
        if ($title === '') {
            return response()->json(['message' => '歌曲标题不能为空'], 400);
        }

        $settings = DB::table('SystemSettings')->first();
        $showBlacklistKeywords = (bool) ($settings->showBlacklistKeywords ?? false);
        $items = DB::table('SongBlacklist')->where('isActive', true)->get();

        $songFullName = mb_strtolower($title.' - '.$artist);
        $blocked = [];

        foreach ($items as $item) {
            $value = mb_strtolower((string) $item->value);
            if ($value === '' || !str_contains($songFullName, $value)) {
                continue;
            }

            if ((string) $item->type === 'SONG') {
                $blocked[] = [
                    'type' => 'song',
                    'value' => $item->value,
                    'reason' => $item->reason ?: '该歌曲已被加入黑名单',
                ];
            } elseif ((string) $item->type === 'KEYWORD') {
                $blocked[] = [
                    'type' => 'keyword',
                    'value' => $showBlacklistKeywords ? $item->value : null,
                    'reason' => $item->reason ?: ($showBlacklistKeywords ? '包含关键词：'.$item->value : '包含关键词'),
                ];
            }
        }

        return response()->json([
            'isBlocked' => count($blocked) > 0,
            'reasons' => $blocked,
            'song' => [
                'title' => $title,
                'artist' => $artist,
            ],
        ]);
    }
}
