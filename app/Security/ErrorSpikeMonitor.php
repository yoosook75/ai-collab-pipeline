<?php

namespace App\Security;

use App\Ops\OpsEvent;
use App\Ops\OpsNotifier;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

class ErrorSpikeMonitor
{
    public function __construct(private IncidentReporter $reporter, private OpsNotifier $notifier) {}

    public function record(Throwable $e): void
    {
        $file = Str::after($e->getFile(), base_path().DIRECTORY_SEPARATOR);
        $file = str_replace('\\', '/', $file);
        $fingerprint = sha1(get_class($e).'|'.$file.'|'.$e->getLine());
        $window = (int) config('security.errors.window_minutes') * 60;

        $countKey = "errors:count:{$fingerprint}";
        $firstKey = "errors:first:{$fingerprint}";
        Cache::add($countKey, 0, $window);
        Cache::add($firstKey, now()->toIso8601String(), $window);
        $count = (int) Cache::increment($countKey);

        if ($count !== (int) config('security.errors.threshold')) {
            return;
        }

        $url = $this->reporter->report([
            'fingerprint' => $fingerprint,
            'class' => get_class($e),
            'message' => Str::limit($e->getMessage(), 500),
            'file' => $file,
            'line' => $e->getLine(),
            'count' => $count,
            'environment' => (string) config('ops.app_env_label'),
            'first_seen' => (string) Cache::get($firstKey, now()->toIso8601String()),
            'last_seen' => now()->toIso8601String(),
        ]);

        $this->notifier->send(new OpsEvent(
            '에러 급증',
            'critical',
            "같은 오류가 {$count}회 반복되었습니다: ".class_basename($e)." at {$file}:{$e->getLine()}"
                .($url ? "\nAI 분석용 이슈를 만들었습니다." : "\n(이슈는 만들지 않음: 중복이거나 하루 상한 도달)"),
            $url,
        ));
    }
}
