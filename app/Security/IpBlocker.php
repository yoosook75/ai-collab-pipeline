<?php

namespace App\Security;

use App\Models\BlockedIp;
use App\Ops\OpsEvent;
use App\Ops\OpsNotifier;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;

class IpBlocker
{
    public function __construct(private OpsNotifier $notifier) {}

    public function isBlocked(string $ip): bool
    {
        return BlockedIp::where('ip', $ip)->where('expires_at', '>', now())->exists();
    }

    public function block(string $ip, string $reason, int $minutes, bool $notify = true): BlockedIp
    {
        $existing = BlockedIp::where('ip', $ip)->first();
        if ($existing && $existing->expires_at->isFuture()) {
            return $existing;
        }

        $block = BlockedIp::updateOrCreate(['ip' => $ip], [
            'reason' => $reason,
            'expires_at' => now()->addMinutes($minutes),
        ]);

        if (! $notify) {
            return $block;
        }

        $this->notifier->send(new OpsEvent(
            'IP 일시 차단',
            'warn',
            "{$reason} — {$minutes}분 동안 차단합니다. 링크를 누르면 바로 해제됩니다(24시간 유효).",
            URL::temporarySignedRoute('ops.unblock', now()->addHours(24), ['ip' => $ip]),
            ['IP' => $ip, '해제 예정' => $block->expires_at->toDateTimeString()],
        ));

        return $block;
    }

    public function unblock(string $ip): void
    {
        BlockedIp::where('ip', $ip)->delete();
        RateLimiter::clear($this->requestKey($ip));
    }

    public function isProbe(string $path): bool
    {
        $path = mb_strtolower(trim($path, '/'));
        foreach ((array) config('security.ip.probe_paths') as $probe) {
            $probe = mb_strtolower(trim($probe, '/'));
            if ($path === $probe || str_starts_with($path, $probe.'/')) {
                return true;
            }
        }

        return false;
    }

    /** Count a request; returns true when the IP is over the flood limit. */
    public function hitAndCheckFlood(string $ip): bool
    {
        $key = $this->requestKey($ip);
        RateLimiter::hit($key, (int) config('security.ip.request_window_minutes') * 60);

        return RateLimiter::attempts($key) > (int) config('security.ip.max_requests');
    }

    private function requestKey(string $ip): string
    {
        return 'ip-req:'.$ip;
    }
}
