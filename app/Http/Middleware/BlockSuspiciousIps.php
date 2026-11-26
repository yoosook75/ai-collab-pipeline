<?php

namespace App\Http\Middleware;

use App\Security\IpBlocker;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BlockSuspiciousIps
{
    public function __construct(private IpBlocker $blocker) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->is_admin || $request->routeIs('ops.unblock')) {
            return $next($request);
        }

        $ip = (string) $request->ip();

        if ($this->blocker->isBlocked($ip)) {
            abort(403);
        }

        if ($this->blocker->isProbe($request->path())) {
            $this->blocker->block($ip, "취약 경로 탐색: /{$request->path()}", (int) config('security.ip.probe_block_minutes'));
            abort(403);
        }

        if ($this->blocker->hitAndCheckFlood($ip)) {
            $this->blocker->block($ip, '과도한 요청', (int) config('security.ip.flood_block_minutes'));
            abort(403);
        }

        return $next($request);
    }
}
