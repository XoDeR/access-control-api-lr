<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $checks = ['app' => 'ok'];
        $status = 200;

        try {
            DB::connection()->getPdo();
            $checks['database'] = 'ok';
        } catch (\Throwable) {
            $checks['database'] = 'error';
            $status = 503;
        }

        try {
            if (config('cache.default') === 'redis') {
                \Illuminate\Support\Facades\Redis::connection()->ping();
                $checks['redis'] = 'ok';
            } else {
                $checks['redis'] = 'skipped';
            }
        } catch (\Throwable) {
            $checks['redis'] = 'error';
            $status = 503;
        }

        return response()->json([
            'status' => $status === 200 ? 'healthy' : 'unhealthy',
            'checks' => $checks,
        ], $status);
    }
}
