<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;

class MediaController extends Controller
{
    /**
     * @var array<string, string>
     */
    private const SOURCE_BASE_URL_MAP = [
        'netease-backup-1' => 'https://api.voicehub.lao-shui.top:443',
        'vkeys-v3' => 'https://api.vkeys.cn/music',
        'vkeys' => 'https://api.vkeys.cn/v2/music',
        'netease-backup-2' => 'https://ncmapi.zcy.life:443',
        'meting-1' => 'https://api.qijieya.cn/meting',
        'meting-2' => 'https://api.obdo.cc/meting',
        'bilibili' => 'https://api.bilibili.com',
    ];

    /**
     * @var string[]
     */
    private const MUSIC_FILE_ALLOWED_SUFFIXES = [
        '.qq.com',
        '.music.126.net',
        '.bilivideo.com',
        '.bilibili.com',
    ];

    /**
     * @var string[]
     */
    private const IMAGE_PROXY_BILIBILI_HOST_MARKERS = [
        'hdslb.com',
        'bilibili.com',
    ];

    /**
     * @var array<string, string>
     */
    private const NETEASE_ENHANCED_API_MAP = [
        'search' => '/search',
        'search_default' => '/search/default',
        'song_url_v1' => '/song/url/v1',
        'song_url' => '/song/url',
        'song_detail' => '/song/detail',
        'playlist_detail' => '/playlist/detail',
        'lyric' => '/lyric',
        'comment_music' => '/comment/music',
    ];

    public function neteaseEnhanced(Request $request, string $path): Response|JsonResponse
    {
        $action = str_replace('-', '_', str_replace('/', '_', trim($path, '/')));
        $targetPath = self::NETEASE_ENHANCED_API_MAP[$action] ?? ('/'.trim($path, '/'));
        $base = rtrim((string) env('NETEASE_ENHANCED_BASE_URL', 'https://api.voicehub.lao-shui.top:443'), '/');
        $targetUrl = $base.$targetPath;

        $method = strtoupper($request->method());
        $query = $this->normalizedInput($request->query());
        $body = in_array($method, ['GET', 'HEAD'], true) ? [] : $this->normalizedInput($request->all());

        try {
            $http = Http::timeout(15)->withHeaders([
                'User-Agent' => 'VoiceHub-Laravel/1.0',
            ]);
            $upstream = $http->send($method, $targetUrl, [
                'query' => $query,
                'json' => $body === [] ? null : $body,
            ]);

            return $this->forwardUpstreamResponse($upstream);
        } catch (\Throwable $exception) {
            return response()->json([
                'message' => '网易云接口调用失败',
                'error' => $exception->getMessage(),
            ], 502);
        }
    }

    public function bilibiliSearch(Request $request): JsonResponse
    {
        $keyword = trim((string) $request->query('keyword', ''));
        if ($keyword === '') {
            return response()->json([]);
        }

        try {
            $searchResponse = Http::timeout(12)
                ->withHeaders($this->bilibiliHeaders())
                ->get('https://api.bilibili.com/x/web-interface/search/type', [
                    '__refresh__' => true,
                    'page' => 1,
                    'page_size' => 15,
                    'platform' => 'pc',
                    'highlight' => 1,
                    'single_column' => 0,
                    'keyword' => $keyword,
                    'search_type' => 'video',
                    'dynamic_offset' => 0,
                    'preload' => true,
                    'com2co' => true,
                ]);

            if (!$searchResponse->ok()) {
                return response()->json([]);
            }

            $items = $searchResponse->json('data.result', []);
            if (!is_array($items)) {
                return response()->json([]);
            }

            $result = [];
            foreach ($items as $song) {
                if (!is_array($song) || empty($song['bvid'])) {
                    continue;
                }
                $pages = [];
                try {
                    $info = Http::timeout(10)
                        ->withHeaders($this->bilibiliHeaders())
                        ->get('https://api.bilibili.com/x/web-interface/view', [
                            'bvid' => $song['bvid'],
                        ]);
                    if ($info->ok()) {
                        $rawPages = $info->json('data.pages', []);
                        if (is_array($rawPages)) {
                            $pages = $rawPages;
                        }
                    }
                } catch (\Throwable) {
                    // Ignore single item lookup failure.
                }

                $result[] = $this->convertBilibiliTrack($song, $pages);
            }

            return response()->json($result);
        } catch (\Throwable) {
            return response()->json([]);
        }
    }

    public function bilibiliPlayurl(Request $request): JsonResponse
    {
        $bvid = trim((string) $request->query('id', ''));
        $cid = trim((string) $request->query('cid', ''));
        if ($bvid === '') {
            return response()->json([
                'message' => 'Missing id parameter',
            ], 400);
        }

        try {
            $finalCid = $cid;
            if ($finalCid === '') {
                $view = Http::timeout(10)
                    ->withHeaders($this->bilibiliHeaders())
                    ->get('https://api.bilibili.com/x/web-interface/view', [
                        'bvid' => $bvid,
                    ]);
                $finalCid = (string) ($view->json('data.pages.0.cid', ''));
            }

            if ($finalCid === '') {
                return response()->json(['url' => '', 'pay' => false]);
            }

            $playUrl = Http::timeout(10)
                ->withHeaders($this->bilibiliHeaders())
                ->get('https://api.bilibili.com/x/player/playurl', [
                    'fnval' => 1,
                    'platform' => 'html5',
                    'high_quality' => 1,
                    'bvid' => $bvid,
                    'cid' => $finalCid,
                ]);

            $url = (string) ($playUrl->json('data.durl.0.url', ''));
            if ($url === '') {
                return response()->json(['url' => '', 'pay' => false]);
            }

            return response()->json([
                'url' => $url,
                'pay' => false,
            ]);
        } catch (\Throwable) {
            return response()->json(['url' => '', 'pay' => false]);
        }
    }

    public function musicProxyGet(Request $request): Response|JsonResponse
    {
        return $this->musicProxy($request, 'GET');
    }

    public function musicProxyPost(Request $request): Response|JsonResponse
    {
        return $this->musicProxy($request, 'POST');
    }

    public function musicFile(Request $request): Response|JsonResponse
    {
        $rawUrl = trim((string) $request->query('url', ''));
        if ($rawUrl === '') {
            return response()->json(['message' => '缺少 url 参数'], 400);
        }

        $parsed = parse_url($rawUrl);
        if (!is_array($parsed) || empty($parsed['scheme']) || empty($parsed['host'])) {
            return response()->json(['message' => 'url 参数无效'], 400);
        }
        $scheme = strtolower((string) $parsed['scheme']);
        $host = strtolower((string) $parsed['host']);

        if ($scheme !== 'https') {
            return response()->json(['message' => '仅允许 HTTPS 资源'], 400);
        }

        if (!$this->isAllowedMusicFileHost($host)) {
            return response()->json(['message' => '目标域名不在允许列表'], 403);
        }

        try {
            $upstream = Http::timeout(20)->withHeaders([
                'User-Agent' => $this->browserLikeUA(),
                'Accept' => '*/*',
            ])->withOptions(['stream' => true])->get($rawUrl);
            if (!$upstream->ok()) {
                return response()->json(['message' => '上游服务不可用'], 502);
            }

            return response($upstream->body(), 200, [
                'Content-Type' => (string) $upstream->header('Content-Type', 'application/octet-stream'),
                'Content-Length' => (string) $upstream->header('Content-Length', ''),
                'Cache-Control' => 'no-store',
            ]);
        } catch (\Throwable) {
            return response()->json(['message' => '上游请求失败'], 502);
        }
    }

    public function proxyImage(Request $request): Response|JsonResponse
    {
        $rawUrl = trim((string) $request->query('url', ''));
        if ($rawUrl === '') {
            return response()->json(['message' => 'Missing image URL parameter'], 400);
        }

        $parsed = parse_url($rawUrl);
        if (!is_array($parsed) || empty($parsed['scheme']) || empty($parsed['host'])) {
            return response()->json(['message' => 'Invalid image URL'], 400);
        }
        $scheme = strtolower((string) $parsed['scheme']);
        $host = strtolower((string) $parsed['host']);

        if (!in_array($scheme, ['http', 'https'], true)) {
            return response()->json(['message' => 'Invalid protocol'], 400);
        }

        $port = isset($parsed['port']) ? ':'.$parsed['port'] : '';
        $referer = $scheme.'://'.$host.$port;
        foreach (self::IMAGE_PROXY_BILIBILI_HOST_MARKERS as $marker) {
            if (str_contains($host, $marker)) {
                $referer = 'https://www.bilibili.com/';
                break;
            }
        }

        $lastError = null;
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                $upstream = Http::timeout(10)->withHeaders([
                    'User-Agent' => $this->browserLikeUA(),
                    'Accept' => 'image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8',
                    'Accept-Language' => 'zh-CN,zh;q=0.9,en;q=0.8',
                    'Cache-Control' => 'no-cache',
                    'Pragma' => 'no-cache',
                    'Sec-Fetch-Dest' => 'image',
                    'Sec-Fetch-Mode' => 'no-cors',
                    'Sec-Fetch-Site' => 'cross-site',
                    'Referer' => $referer,
                ])->get($rawUrl);

                if (!$upstream->ok()) {
                    throw new \RuntimeException('HTTP '.$upstream->status());
                }
                $contentType = strtolower((string) $upstream->header('Content-Type', ''));
                if (!str_starts_with($contentType, 'image/')) {
                    throw new \RuntimeException('Response is not an image');
                }

                return response($upstream->body(), 200, [
                    'Content-Type' => $contentType,
                    'Cache-Control' => 'public, max-age=3600',
                    'Access-Control-Allow-Origin' => '*',
                    'Access-Control-Allow-Methods' => 'GET, OPTIONS',
                    'Access-Control-Allow-Headers' => 'Content-Type',
                ]);
            } catch (\Throwable $exception) {
                $lastError = $exception;
                if ($attempt < 3) {
                    usleep(min(5000000, (1000000 * (2 ** ($attempt - 1)))));
                }
            }
        }

        return response()->json([
            'message' => 'Failed to fetch image',
            'error' => $lastError?->getMessage(),
        ], 500);
    }

    public function nativeSearchWy(Request $request): JsonResponse
    {
        $keyword = trim((string) $request->query('str', ''));
        $page = max(1, (int) $request->query('page', 1));
        $limit = max(1, min(50, (int) $request->query('limit', 30)));
        if ($keyword === '') {
            return response()->json(['message' => 'Missing search query'], 400);
        }

        $offset = $limit * ($page - 1);

        try {
            $response = Http::timeout(12)->withHeaders([
                'User-Agent' => $this->browserLikeUA(),
                'Referer' => 'https://music.163.com/',
            ])->get('https://music.163.com/api/search/get/web', [
                's' => $keyword,
                'type' => 1,
                'offset' => $offset,
                'limit' => $limit,
            ]);

            if (!$response->ok() || (int) $response->json('code', 0) !== 200) {
                return response()->json([
                    'list' => [],
                    'total' => 0,
                    'page' => $page,
                    'limit' => $limit,
                    'source' => 'wy',
                ]);
            }

            $songs = $response->json('result.songs', []);
            if (!is_array($songs)) {
                $songs = [];
            }
            $list = array_map(function ($item) {
                $artists = [];
                if (isset($item['artists']) && is_array($item['artists'])) {
                    foreach ($item['artists'] as $a) {
                        if (!is_array($a)) {
                            continue;
                        }
                        $artists[] = (string) ($a['name'] ?? '');
                    }
                }

                $durationSec = (int) round(((int) ($item['duration'] ?? 0)) / 1000);
                $types = [
                    ['type' => '128k', 'size' => null],
                    ['type' => '320k', 'size' => null],
                    ['type' => 'flac', 'size' => null],
                ];

                return [
                    'singer' => implode('、', array_values(array_filter($artists))),
                    'name' => (string) ($item['name'] ?? ''),
                    'albumName' => (string) ($item['album']['name'] ?? ''),
                    'albumId' => (int) ($item['album']['id'] ?? 0),
                    'source' => 'wy',
                    'interval' => $this->formatPlayTime($durationSec),
                    'duration' => $durationSec,
                    'songmid' => (string) ($item['id'] ?? ''),
                    'img' => (string) ($item['album']['picUrl'] ?? ''),
                    'lrc' => null,
                    'types' => $types,
                    '_types' => [
                        '128k' => ['size' => null],
                        '320k' => ['size' => null],
                        'flac' => ['size' => null],
                    ],
                    'typeUrl' => [],
                ];
            }, $songs);

            return response()->json([
                'list' => $list,
                'total' => (int) $response->json('result.songCount', 0),
                'page' => $page,
                'limit' => $limit,
                'source' => 'wy',
            ]);
        } catch (\Throwable) {
            return response()->json([
                'list' => [],
                'total' => 0,
                'page' => $page,
                'limit' => $limit,
                'source' => 'wy',
            ]);
        }
    }

    public function nativeSearchTx(Request $request): JsonResponse
    {
        $keyword = trim((string) $request->query('str', ''));
        $page = max(1, (int) $request->query('page', 1));
        $limit = max(1, min(50, (int) $request->query('limit', 50)));
        if ($keyword === '') {
            return response()->json(['message' => 'Missing search query'], 400);
        }

        $body = [
            'comm' => [
                'ct' => '11',
                'cv' => '14090508',
                'v' => '14090508',
                'tmeAppID' => 'qqmusic',
                'phonetype' => 'EBG-AN10',
                'deviceScore' => '553.47',
                'devicelevel' => '50',
                'newdevicelevel' => '20',
                'rom' => 'HuaWei/EMOTION/EmotionUI_14.2.0',
                'os_ver' => '12',
                'OpenUDID' => '0',
                'OpenUDID2' => '0',
                'QIMEI36' => '0',
                'udid' => '0',
                'chid' => '0',
                'aid' => '0',
                'oaid' => '0',
                'taid' => '0',
                'tid' => '0',
                'wid' => '0',
                'uid' => '0',
                'sid' => '0',
                'modeSwitch' => '6',
                'teenMode' => '0',
                'ui_mode' => '2',
                'nettype' => '1020',
                'v4ip' => '',
            ],
            'req' => [
                'module' => 'music.search.SearchCgiService',
                'method' => 'DoSearchForQQMusicMobile',
                'param' => [
                    'search_type' => 0,
                    'query' => $keyword,
                    'page_num' => $page,
                    'num_per_page' => $limit,
                    'highlight' => 0,
                    'nqc_flag' => 0,
                    'multi_zhida' => 0,
                    'cat' => 2,
                    'grp' => 1,
                    'sin' => 0,
                    'sem' => 0,
                ],
            ],
        ];

        $endpoint = (string) env('TX_API_URL', 'https://u.y.qq.com/cgi-bin/musicu.fcg');

        try {
            $result = Http::timeout(12)->withHeaders([
                'User-Agent' => 'QQMusic 14090508(android 12)',
            ])->post($endpoint, $body)->json();

            if (!is_array($result) || (int) ($result['code'] ?? -1) !== 0 || (int) ($result['req']['code'] ?? -1) !== 0) {
                return response()->json([
                    'list' => [],
                    'total' => 0,
                    'page' => $page,
                    'limit' => $limit,
                    'source' => 'tx',
                ]);
            }

            $items = $result['req']['data']['body']['item_song'] ?? [];
            if (!is_array($items)) {
                $items = [];
            }

            $list = [];
            foreach ($items as $item) {
                if (!is_array($item) || empty($item['file']['media_mid'])) {
                    continue;
                }

                $types = [];
                $_types = [];
                $file = is_array($item['file']) ? $item['file'] : [];
                $this->appendTxType($types, $_types, '128k', (int) ($file['size_128mp3'] ?? 0));
                $this->appendTxType($types, $_types, '320k', (int) ($file['size_320mp3'] ?? 0));
                $this->appendTxType($types, $_types, 'flac', (int) ($file['size_flac'] ?? 0));
                $this->appendTxType($types, $_types, 'flac24bit', (int) ($file['size_hires'] ?? 0));

                $album = is_array($item['album'] ?? null) ? $item['album'] : [];
                $albumId = (string) ($album['mid'] ?? '');
                $albumName = (string) ($album['name'] ?? '');

                $singers = [];
                foreach (($item['singer'] ?? []) as $s) {
                    if (!is_array($s)) {
                        continue;
                    }
                    $singers[] = (string) ($s['name'] ?? '');
                }

                $duration = (int) ($item['interval'] ?? 0);
                $list[] = [
                    'singer' => $this->decodeName(implode('、', array_values(array_filter($singers)))),
                    'name' => $this->decodeName((string) ($item['name'] ?? '').(string) ($item['title_extra'] ?? '')),
                    'albumName' => $this->decodeName($albumName),
                    'albumId' => $albumId,
                    'source' => 'tx',
                    'interval' => $this->formatPlayTime($duration),
                    'duration' => $duration,
                    'songId' => (int) ($item['id'] ?? 0),
                    'albumMid' => $albumId,
                    'strMediaMid' => (string) $file['media_mid'],
                    'songmid' => (string) ($item['mid'] ?? ''),
                    'img' => $albumId === ''
                        ? '': ('https://y.gtimg.cn/music/photo_new/T002R500x500M000'.$albumId.'.jpg'),
                    'types' => $types,
                    '_types' => $_types,
                    'typeUrl' => [],
                ];
            }

            return response()->json([
                'list' => $list,
                'total' => (int) ($result['req']['data']['meta']['estimate_sum'] ?? 0),
                'page' => $page,
                'limit' => $limit,
                'source' => 'tx',
            ]);
        } catch (\Throwable) {
            return response()->json([
                'list' => [],
                'total' => 0,
                'page' => $page,
                'limit' => $limit,
                'source' => 'tx',
            ]);
        }
    }

    private function musicProxy(Request $request, string $method): Response|JsonResponse
    {
        $source = trim((string) $request->query('source', ''));
        $path = trim((string) $request->query('path', ''));
        $rawQ = trim((string) $request->query('q', ''));
        $responseType = trim((string) $request->query('responseType', 'json'));
        $timeout = $this->parseTimeout((string) $request->query('timeout', ''));

        $baseUrl = self::SOURCE_BASE_URL_MAP[$source] ?? null;
        if ($baseUrl === null) {
            return response()->json(['message' => '未知音源: '.$source], 400);
        }
        if ($path === '' || !str_starts_with($path, '/')) {
            return response()->json(['message' => 'path 参数无效'], 400);
        }

        $targetUrl = rtrim($baseUrl, '/').$path.($rawQ !== '' ? ('?'.$rawQ) : '');
        try {
            $http = Http::timeout($timeout / 1000)->withHeaders([
                'User-Agent' => $this->browserLikeUA(),
                'Accept' => '*/*',
            ]);
            $upstream = $method === 'POST'
                ? $http->post($targetUrl, $request->all())
                : $http->get($targetUrl);

            if (!$upstream->ok()) {
                return response()->json(['message' => '上游服务不可用'], 502);
            }

            if ($responseType === 'resolve') {
                return response()->json([
                    'url' => $targetUrl,
                    'status' => $upstream->status(),
                ]);
            }

            if ($responseType === 'text') {
                return response($upstream->body(), 200, [
                    'Content-Type' => (string) $upstream->header('Content-Type', 'text/plain; charset=utf-8'),
                ]);
            }

            $json = $upstream->json();
            if (is_array($json)) {
                return response()->json($json);
            }

            return response($upstream->body(), 200, [
                'Content-Type' => (string) $upstream->header('Content-Type', 'text/plain; charset=utf-8'),
            ]);
        } catch (\Throwable) {
            return response()->json(['message' => '上游请求失败'], 502);
        }
    }

    private function browserLikeUA(): string
    {
        return 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function normalizedInput(array $input): array
    {
        $result = [];
        foreach ($input as $key => $value) {
            if ($value === null || $value === '' || $value === []) {
                continue;
            }
            $result[$key] = is_array($value) ? end($value) : $value;
        }

        return $result;
    }

    private function parseTimeout(string $value): int
    {
        $n = (int) $value;
        if ($n <= 0) {
            return 8000;
        }

        return min(15000, $n);
    }

    /**
     * @param array<string, mixed> $song
     * @param array<int, array<string, mixed>> $pages
     * @return array<string, mixed>
     */
    private function convertBilibiliTrack(array $song, array $pages): array
    {
        $pic = (string) ($song['pic'] ?? '');
        if (str_starts_with($pic, '//')) {
            $pic = 'https:'.$pic;
        }

        $duration = $this->durationStringToSeconds((string) ($song['duration'] ?? '0:00'));
        $title = strip_tags((string) ($song['title'] ?? ''));
        $author = strip_tags((string) ($song['author'] ?? ''));

        return [
            'id' => (string) ($song['bvid'] ?? ''),
            'title' => $title,
            'artist' => $author,
            'source' => 'bilibili',
            'musicPlatform' => 'bilibili',
            'cover' => $pic,
            'duration' => $duration,
            'album' => 'Bilibili Video',
            'pages' => $pages,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function bilibiliHeaders(): array
    {
        return [
            'Cookie' => 'buvid3=0',
            'Referer' => 'https://www.bilibili.com/',
            'User-Agent' => $this->browserLikeUA(),
        ];
    }

    private function durationStringToSeconds(string $duration): int
    {
        $parts = array_reverse(array_map('intval', explode(':', $duration)));
        $seconds = $parts[0] ?? 0;
        if (isset($parts[1])) {
            $seconds += $parts[1] * 60;
        }
        if (isset($parts[2])) {
            $seconds += $parts[2] * 3600;
        }

        return max(0, $seconds);
    }

    private function isAllowedMusicFileHost(string $hostname): bool
    {
        foreach (self::MUSIC_FILE_ALLOWED_SUFFIXES as $suffix) {
            if (str_ends_with($hostname, $suffix)) {
                return true;
            }
        }

        return false;
    }

    private function formatPlayTime(int $seconds): string
    {
        if ($seconds < 0) {
            $seconds = 0;
        }
        $m = (int) floor($seconds / 60);
        $s = $seconds % 60;

        return sprintf('%02d:%02d', $m, $s);
    }

    private function decodeName(string $value): string
    {
        return html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * @param array<int, array<string, mixed>> $types
     * @param array<string, array<string, mixed>> $_types
     */
    private function appendTxType(array &$types, array &$_types, string $name, int $sizeBytes): void
    {
        if ($sizeBytes <= 0) {
            return;
        }
        $size = $this->sizeFormat($sizeBytes);
        $types[] = ['type' => $name, 'size' => $size];
        $_types[$name] = ['size' => $size];
    }

    private function sizeFormat(int $size): string
    {
        if ($size <= 0) {
            return '0B';
        }
        $units = ['B', 'KB', 'MB', 'GB'];
        $index = 0;
        $value = (float) $size;
        while ($value >= 1024 && $index < count($units) - 1) {
            $value /= 1024;
            $index++;
        }

        return round($value, $index === 0 ? 0 : 2).$units[$index];
    }

    private function forwardUpstreamResponse($upstream): Response|JsonResponse
    {
        $status = (int) $upstream->status();
        $contentType = strtolower((string) $upstream->header('Content-Type', ''));
        $headers = [];
        foreach (['set-cookie', 'content-disposition', 'cache-control'] as $headerName) {
            $value = $upstream->header($headerName);
            if ($value !== null && $value !== '') {
                $headers[$headerName] = $value;
            }
        }

        if (str_contains($contentType, 'application/json')) {
            return response()->json($upstream->json(), $status, $headers);
        }

        return response($upstream->body(), $status, array_merge($headers, [
            'Content-Type' => $contentType !== '' ? $contentType : 'text/plain; charset=utf-8',
        ]));
    }
}
