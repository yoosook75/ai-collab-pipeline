<?php

namespace App\Security;

use App\Models\User;
use App\Ops\OpsEvent;
use App\Ops\OpsNotifier;
use Illuminate\Support\Facades\RateLimiter;

class LoginLockout
{
    public function __construct(private OpsNotifier $notifier) {}

    /**
     * Short throttle that applies to everyone, admins included: it delays guessing
     * without locking anyone out.
     */
    public function tooManyAttempts(string $email): bool
    {
        return RateLimiter::tooManyAttempts($this->throttleKey($email), (int) config('security.login.throttle_per_minute'));
    }

    public function recordFailure(string $email): void
    {
        RateLimiter::hit($this->throttleKey($email), 60);

        $key = $this->key($email);
        RateLimiter::hit($key, (int) config('security.login.window_minutes') * 60);

        if (RateLimiter::attempts($key) < (int) config('security.login.max_failures')) {
            return;
        }

        $user = User::where('email', $email)->first();
        if (! $user || $user->is_admin || $user->locked_at) {
            return;
        }

        $user->forceFill(['locked_at' => now()])->save();

        $this->notifier->send(new OpsEvent(
            '계정 잠금',
            'warn',
            "로그인 연속 실패로 사용자 #{$user->id} 계정을 잠갔습니다. 본인이 이메일 인증으로 해제할 수 있습니다.",
            config('app.url'),
            ['사용자' => "#{$user->id}", '실패 횟수' => (string) RateLimiter::attempts($key)],
        ));
    }

    public function clear(string $email): void
    {
        RateLimiter::clear($this->key($email));
        RateLimiter::clear($this->throttleKey($email));
    }

    private function throttleKey(string $email): string
    {
        return 'login-throttle:'.sha1(mb_strtolower(trim($email)));
    }

    private function key(string $email): string
    {
        return 'login-fail:'.sha1(mb_strtolower(trim($email)));
    }
}
