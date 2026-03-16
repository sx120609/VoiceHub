<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

$rawBasePath = trim((string) env('APP_BASE_PATH', '/rareapp'));
$normalizedBasePath = $rawBasePath === '' || $rawBasePath === '/'
    ? ''
    : '/'.trim($rawBasePath, '/');
$apiPrefix = trim(preg_replace('#/+#', '/', $normalizedBasePath.'/api') ?: 'api', '/');

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: $apiPrefix,
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'auth.jwt' => \App\Http\Middleware\AuthenticateJwt::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
