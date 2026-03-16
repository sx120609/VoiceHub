<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SystemController extends Controller
{
    public function status(): JsonResponse
    {
        try {
            $dbConnected = false;
            $dbError = null;
            try {
                DB::select('SELECT 1');
                $dbConnected = true;
            } catch (\Throwable $exception) {
                $dbError = $exception->getMessage();
            }

            $poolStatus = [
                'driver' => config('database.default'),
                'connected' => $dbConnected,
            ];

            return response()->json([
                'status' => $dbConnected ? 'ok' : 'error',
                'database' => [
                    'connected' => $dbConnected,
                    'poolStatus' => $poolStatus,
                    'connectionInfo' => [
                        'connected' => $dbConnected,
                        'driver' => config('database.default'),
                        'database' => config('database.connections.'.config('database.default').'.database'),
                    ],
                    'error' => $dbError,
                ],
                'system' => [
                    'timestamp' => Carbon::now()->toISOString(),
                    'uptime' => (int) floor(microtime(true) - LARAVEL_START),
                    'phpVersion' => PHP_VERSION,
                    'platform' => PHP_OS_FAMILY,
                    'memory' => [
                        'used' => (int) round(memory_get_usage(true) / 1024 / 1024),
                        'peak' => (int) round(memory_get_peak_usage(true) / 1024 / 1024),
                    ],
                ],
            ]);
        } catch (\Throwable $exception) {
            return response()->json([
                'status' => 'error',
                'error' => $exception->getMessage(),
                'database' => [
                    'connected' => false,
                    'poolStatus' => null,
                    'error' => $exception->getMessage(),
                ],
            ], 500);
        }
    }

    public function reconnect(): JsonResponse
    {
        try {
            DB::disconnect();
            DB::reconnect();
            DB::select('SELECT 1');

            return response()->json([
                'success' => true,
                'message' => '数据库连接正常',
                'connectionStatus' => [
                    'connected' => true,
                ],
                'poolStatus' => [
                    'driver' => config('database.default'),
                    'reconnected' => true,
                ],
                'timestamp' => Carbon::now()->toISOString(),
            ]);
        } catch (\Throwable $exception) {
            return response()->json([
                'success' => false,
                'message' => '数据库连接异常',
                'error' => $exception->getMessage(),
                'connectionStatus' => [
                    'connected' => false,
                    'error' => $exception->getMessage(),
                ],
                'poolStatus' => [
                    'driver' => config('database.default'),
                    'reconnected' => false,
                ],
                'timestamp' => Carbon::now()->toISOString(),
            ]);
        }
    }

    public function dbStatus(): JsonResponse
    {
        $status = [
            'connected' => false,
            'tables' => [
                'user' => false,
                'song' => false,
                'vote' => false,
                'schedule' => false,
                'notification' => false,
            ],
            'userCount' => 0,
        ];

        try {
            DB::select('SELECT 1');
            $status['connected'] = true;
        } catch (\Throwable $exception) {
            return response()->json([
                'success' => false,
                'status' => $status,
                'error' => '数据库连接失败: '.$exception->getMessage(),
            ]);
        }

        $tableMap = [
            'user' => 'User',
            'song' => 'Song',
            'vote' => 'Vote',
            'schedule' => 'Schedule',
            'notification' => 'Notification',
        ];
        foreach ($tableMap as $key => $table) {
            try {
                $status['tables'][$key] = Schema::hasTable($table);
            } catch (\Throwable) {
                $status['tables'][$key] = false;
            }
        }

        if ($status['tables']['user']) {
            try {
                $status['userCount'] = (int) DB::table('User')->count();
            } catch (\Throwable) {
                $status['userCount'] = 0;
            }
        }

        return response()->json([
            'success' => true,
            'status' => $status,
        ]);
    }
}
