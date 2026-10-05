<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Operational readiness for the Laravel service and its dependencies.
 *
 * Not a business endpoint. Reports whether PostgreSQL and Redis are reachable
 * from Laravel (foundation checklist). Never includes configuration values.
 */
class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $checks = [
            'database' => $this->check(fn () => DB::connection()->getPdo() !== null),
            'redis' => $this->check(fn () => (string) Redis::connection()->ping() !== ''),
        ];

        $healthy = ! in_array('down', $checks, true);

        return response()->json([
            'data' => [
                'status' => $healthy ? 'ok' : 'degraded',
                'service' => 'dataspeak-backend',
                'version' => '1.0',
                'checks' => $checks,
            ],
        ], $healthy ? 200 : 503);
    }

    /** @param  callable(): bool  $probe */
    private function check(callable $probe): string
    {
        try {
            return $probe() ? 'ok' : 'down';
        } catch (Throwable) {
            return 'down';
        }
    }
}
