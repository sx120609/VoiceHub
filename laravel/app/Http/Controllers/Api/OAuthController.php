<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\JwtToken;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class OAuthController extends Controller
{
    public function redirectToProvider(Request $request, string $provider): RedirectResponse
    {
        $provider = strtolower(trim($provider));
        $origin = $this->requestOrigin($request);
        $redirectUri = $this->providerRedirectUri($provider);
        $csrf = bin2hex(random_bytes(16));
        $state = encrypt(json_encode([
            'target' => $origin,
            'csrf' => $csrf,
            'timestamp' => round(microtime(true) * 1000),
            'provider' => $provider,
        ], JSON_UNESCAPED_UNICODE));

        $response = redirect()->away($this->providerAuthorizeUrl($provider, $redirectUri, $state));
        $response->headers->setCookie(cookie(
            'oauth_csrf',
            $csrf,
            10,
            '/',
            config('session.domain'),
            $request->isSecure() || strtolower((string) $request->header('x-forwarded-proto', '')) === 'https',
            true,
            false,
            'lax'
        ));

        return $response;
    }

    public function callback(Request $request, string $provider): RedirectResponse
    {
        $provider = strtolower(trim($provider));
        $code = trim((string) $request->query('code', ''));
        $stateRaw = trim((string) $request->query('state', ''));
        if ($code === '' || $stateRaw === '') {
            return $this->redirectWithError('/auth/error', 'TOKEN_EXCHANGE_FAILED', '缺少 code 或 state');
        }

        $state = $this->verifyState($request, $stateRaw, $provider);
        if ($state === null) {
            return $this->redirectWithError('/auth/error', 'STATE_INVALID', '授权状态无效或已过期');
        }

        try {
            $redirectUri = $this->providerRedirectUri($provider);
            $accessToken = $this->providerExchangeToken($provider, $code, $redirectUri);
            $userInfo = $this->providerUserInfo($provider, $accessToken);
        } catch (\Throwable $exception) {
            return $this->redirectWithError('/auth/error', 'TOKEN_EXCHANGE_FAILED', '授权失败: '.$exception->getMessage());
        }

        $providerUserId = (string) ($userInfo['id'] ?? '');
        $providerUsername = (string) ($userInfo['username'] ?? '');
        if ($providerUserId === '') {
            return $this->redirectWithError('/auth/error', 'USER_INFO_FAILED', '获取用户信息失败');
        }

        $currentUser = $this->currentUserFromCookie($request);
        $existing = DB::table('UserIdentity')
            ->where('provider', $provider)
            ->where('providerUserId', $providerUserId)
            ->first();

        if ($currentUser !== null) {
            if ($existing !== null) {
                if ((int) $existing->userId === (int) $currentUser->id) {
                    return $this->redirectWithMessage('/account', '账号已绑定');
                }

                return $this->redirectWithError('/account', 'BIND_CONFLICT', '该账号已被其他用户绑定');
            }

            DB::table('UserIdentity')->insert([
                'userId' => $currentUser->id,
                'provider' => $provider,
                'providerUserId' => $providerUserId,
                'providerUsername' => $providerUsername !== '' ? $providerUsername : null,
                'createdAt' => now(),
            ]);

            return $this->redirectWithMessage('/account', '绑定成功');
        }

        if ($existing !== null) {
            $user = DB::table('User')->where('id', $existing->userId)->first();
            if ($user === null || (string) ($user->status ?? '') === 'withdrawn') {
                return $this->redirectWithError('/auth/error', 'ACCOUNT_WITHDRAWN', '账号已注销');
            }

            $token = JwtToken::generate((int) $user->id, (string) ($user->role ?? 'USER'));
            $response = redirect($this->appUrl('/'));
            $response->headers->setCookie(cookie(
                'auth-token',
                $token,
                60 * 24 * 7,
                '/',
                config('session.domain'),
                $request->isSecure() || strtolower((string) $request->header('x-forwarded-proto', '')) === 'https',
                true,
                false,
                'lax'
            ));

            return $response;
        }

        $bindingToken = encrypt(json_encode([
            'provider' => $provider,
            'providerUserId' => $providerUserId,
            'providerUsername' => $providerUsername,
            'exp' => time() + 600,
        ], JSON_UNESCAPED_UNICODE));

        $response = redirect($this->appUrl('/login?action=bind&provider='.rawurlencode($provider).'&username='.rawurlencode($providerUsername)));
        $response->headers->setCookie(cookie(
            'binding-token',
            $bindingToken,
            10,
            '/',
            config('session.domain'),
            $request->isSecure() || strtolower((string) $request->header('x-forwarded-proto', '')) === 'https',
            true,
            false,
            'lax'
        ));

        return $response;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function verifyState(Request $request, string $stateRaw, string $provider): ?array
    {
        try {
            $decoded = json_decode((string) decrypt($stateRaw), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($decoded)) {
                return null;
            }
            if ((string) ($decoded['provider'] ?? '') !== $provider) {
                return null;
            }
            if ((int) round(microtime(true) * 1000) - (int) ($decoded['timestamp'] ?? 0) > 10 * 60 * 1000) {
                return null;
            }
            if ((string) ($decoded['target'] ?? '') !== $this->requestOrigin($request)) {
                return null;
            }
            if ((string) ($decoded['csrf'] ?? '') === '' || (string) $request->cookie('oauth_csrf', '') !== (string) $decoded['csrf']) {
                return null;
            }
            cookie()->queue(cookie('oauth_csrf', '', -60, '/'));

            return $decoded;
        } catch (\Throwable) {
            return null;
        }
    }

    private function requestOrigin(Request $request): string
    {
        $proto = strtolower((string) $request->header('x-forwarded-proto', ''));
        if ($proto === '') {
            $proto = $request->isSecure() ? 'https' : 'http';
        }
        $host = (string) $request->header('host', parse_url(config('app.url'), PHP_URL_HOST));

        return $proto.'://'.$host;
    }

    private function providerRedirectUri(string $provider): string
    {
        $template = trim((string) env('OAUTH_REDIRECT_URI', ''));
        if ($template !== '') {
            $uri = str_replace('[provider]', $provider, $template);
            $uri = str_replace('/provider/callback', '/'.$provider.'/callback', $uri);
            if ($uri !== '') {
                return $uri;
            }
        }

        return rtrim((string) config('app.url'), '/').$this->appUrl('/api/auth/'.$provider.'/callback');
    }

    private function providerAuthorizeUrl(string $provider, string $redirectUri, string $state): string
    {
        return match ($provider) {
            'github' => 'https://github.com/login/oauth/authorize?client_id='.rawurlencode((string) env('GITHUB_CLIENT_ID')).'&redirect_uri='.rawurlencode($redirectUri).'&scope=user:email&state='.rawurlencode($state),
            'google' => 'https://accounts.google.com/o/oauth2/v2/auth?client_id='.rawurlencode((string) env('GOOGLE_CLIENT_ID')).'&redirect_uri='.rawurlencode($redirectUri).'&response_type=code&scope=openid%20email%20profile&state='.rawurlencode($state),
            'casdoor' => rtrim((string) env('CASDOOR_ENDPOINT'), '/').'/login/oauth/authorize?client_id='.rawurlencode((string) env('CASDOOR_CLIENT_ID')).'&response_type=code&redirect_uri='.rawurlencode($redirectUri).'&scope=read&state='.rawurlencode($state),
            default => throw new \InvalidArgumentException('Unknown provider: '.$provider),
        };
    }

    private function providerExchangeToken(string $provider, string $code, string $redirectUri): string
    {
        $response = match ($provider) {
            'github' => Http::asForm()->acceptJson()->post('https://github.com/login/oauth/access_token', [
                'client_id' => env('GITHUB_CLIENT_ID'),
                'client_secret' => env('GITHUB_CLIENT_SECRET'),
                'code' => $code,
                'redirect_uri' => $redirectUri,
            ]),
            'google' => Http::asForm()->acceptJson()->post('https://oauth2.googleapis.com/token', [
                'client_id' => env('GOOGLE_CLIENT_ID'),
                'client_secret' => env('GOOGLE_CLIENT_SECRET'),
                'code' => $code,
                'grant_type' => 'authorization_code',
                'redirect_uri' => $redirectUri,
            ]),
            'casdoor' => Http::asForm()->acceptJson()->post(rtrim((string) env('CASDOOR_ENDPOINT'), '/').'/login/oauth/access_token', [
                'client_id' => env('CASDOOR_CLIENT_ID'),
                'client_secret' => env('CASDOOR_CLIENT_SECRET'),
                'code' => $code,
                'grant_type' => 'authorization_code',
                'redirect_uri' => $redirectUri,
            ]),
            default => throw new \InvalidArgumentException('Unknown provider: '.$provider),
        };

        if (!$response->ok() || (string) $response->json('access_token', '') === '') {
            $message = (string) ($response->json('error_description', '') ?: $response->json('error', '令牌请求失败'));
            throw new \RuntimeException($message);
        }

        return (string) $response->json('access_token');
    }

    /**
     * @return array<string, mixed>
     */
    private function providerUserInfo(string $provider, string $accessToken): array
    {
        return match ($provider) {
            'github' => $this->mapGithubUserInfo(Http::withToken($accessToken)->acceptJson()->get('https://api.github.com/user')->json()),
            'google' => $this->mapGoogleUserInfo(Http::withToken($accessToken)->acceptJson()->get('https://www.googleapis.com/oauth2/v3/userinfo')->json()),
            'casdoor' => $this->mapCasdoorUserInfo($this->fetchCasdoorUserInfo($accessToken)),
            default => throw new \InvalidArgumentException('Unknown provider: '.$provider),
        };
    }

    /**
     * @param mixed $payload
     * @return array<string, mixed>
     */
    private function mapGithubUserInfo($payload): array
    {
        if (!is_array($payload)) {
            throw new \RuntimeException('获取用户信息失败');
        }

        return [
            'id' => (string) ($payload['id'] ?? ''),
            'username' => (string) ($payload['login'] ?? ''),
            'name' => (string) ($payload['name'] ?? ''),
            'email' => (string) ($payload['email'] ?? ''),
            'avatar' => (string) ($payload['avatar_url'] ?? ''),
        ];
    }

    /**
     * @param mixed $payload
     * @return array<string, mixed>
     */
    private function mapGoogleUserInfo($payload): array
    {
        if (!is_array($payload)) {
            throw new \RuntimeException('获取用户信息失败');
        }

        return [
            'id' => (string) ($payload['sub'] ?? ''),
            'username' => (string) ($payload['email'] ?? ''),
            'name' => (string) ($payload['name'] ?? ''),
            'email' => (string) ($payload['email'] ?? ''),
            'avatar' => (string) ($payload['picture'] ?? ''),
        ];
    }

    /**
     * @param mixed $payload
     * @return array<string, mixed>
     */
    private function mapCasdoorUserInfo($payload): array
    {
        if (!is_array($payload)) {
            throw new \RuntimeException('获取用户信息失败');
        }

        return [
            'id' => (string) ($payload['id'] ?? $payload['sub'] ?? ''),
            'username' => (string) ($payload['name'] ?? $payload['preferred_username'] ?? $payload['username'] ?? ''),
            'name' => (string) ($payload['displayName'] ?? $payload['name'] ?? ''),
            'email' => (string) ($payload['email'] ?? ''),
            'avatar' => (string) ($payload['avatar'] ?? ''),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchCasdoorUserInfo(string $accessToken): array
    {
        $endpoint = rtrim((string) env('CASDOOR_ENDPOINT'), '/');
        if ($endpoint === '') {
            throw new \RuntimeException('Casdoor 配置缺失');
        }

        $userinfo = Http::withToken($accessToken)->acceptJson()->get($endpoint.'/api/userinfo');
        if ($userinfo->ok() && is_array($userinfo->json())) {
            return $userinfo->json();
        }

        $account = Http::withToken($accessToken)->acceptJson()->get($endpoint.'/api/get-account');
        if ($account->ok() && is_array($account->json())) {
            return $account->json();
        }

        throw new \RuntimeException('获取用户信息失败');
    }

    private function appUrl(string $path): string
    {
        $base = trim((string) env('APP_BASE_PATH', '/rareapp'));
        $base = $base === '' || $base === '/' ? '' : '/'.trim($base, '/');
        $path = '/'.ltrim($path, '/');
        if ($base !== '' && Str::startsWith($path, $base.'/')) {
            return $path;
        }

        return $base.$path;
    }

    private function redirectWithError(string $path, string $code, string $message): RedirectResponse
    {
        $target = $this->appUrl($path.'?code='.rawurlencode($code).'&message='.rawurlencode($message));

        return redirect($target);
    }

    private function redirectWithMessage(string $path, string $message): RedirectResponse
    {
        $target = $this->appUrl($path.'?message='.rawurlencode($message));

        return redirect($target);
    }

    private function currentUserFromCookie(Request $request): ?object
    {
        $token = (string) $request->cookie('auth-token', '');
        if ($token === '') {
            return null;
        }
        try {
            $payload = JwtToken::verify($token);
            $userId = (int) ($payload['userId'] ?? 0);
            if ($userId <= 0) {
                return null;
            }

            return DB::table('User')->where('id', $userId)->first();
        } catch (\Throwable) {
            return null;
        }
    }
}
