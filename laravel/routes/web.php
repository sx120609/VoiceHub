<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect('/rareapp/');
});

Route::get('/health', function () {
    return response()->json([
        'name' => 'VoiceHub Laravel API',
        'status' => 'ok',
    ]);
});

Route::get('/rareapp/{path?}', function (?string $path = null) {
    $baseDir = public_path('rareapp');
    $baseRealPath = realpath($baseDir);

    if ($baseRealPath === false) {
        abort(404, 'Frontend not deployed: /public/rareapp not found.');
    }

    $relativePath = trim((string) $path, '/');
    $targetPath = $relativePath === '' ? $baseRealPath.DIRECTORY_SEPARATOR.'index.html' : $baseRealPath.DIRECTORY_SEPARATOR.$relativePath;
    $targetRealPath = realpath($targetPath);

    if (
        $targetRealPath !== false &&
        str_starts_with($targetRealPath, $baseRealPath) &&
        is_file($targetRealPath)
    ) {
        return response()->file($targetRealPath);
    }

    $indexPath = $baseRealPath.DIRECTORY_SEPARATOR.'index.html';
    if (! is_file($indexPath)) {
        abort(404, 'Frontend entry not found: /public/rareapp/index.html');
    }

    return response()->file($indexPath);
})->where('path', '^(?!api(?:/|$)).*');
