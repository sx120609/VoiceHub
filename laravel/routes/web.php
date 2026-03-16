<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'name' => 'VoiceHub Laravel API',
        'status' => 'ok',
    ]);
});
