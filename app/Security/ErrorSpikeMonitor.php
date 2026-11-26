<?php

namespace App\Security;

use App\Ops\OpsEvent;
use App\Ops\OpsNotifier;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

class ErrorSpikeMonitor
{
    private const ALERT_COOLDOWN_MINUTES = 60;

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
            'message' => self::safeMessage($e),
            'file' => $file,
            'line' => $e->getLine(),
            'count' => $count,
            'environment' => (string) config('ops.app_env_label'),
            'first_seen' => (string) Cache::get($firstKey, now()->toIso8601String()),
            'last_seen' => now()->toIso8601String(),
        ]);

        // A sustained error crosses the threshold every window; alert at most once per cooldown.
        if (! Cache::add("errors:alerted:{$fingerprint}", true, now()->addMinutes(self::ALERT_COOLDOWN_MINUTES))) {
            return;
        }

        $this->notifier->send(new OpsEvent(
            '에러 급증',
            'critical',
            "같은 오류가 {$count}회 반복되었습니다: ".class_basename($e)." at {$file}:{$e->getLine()}"
                .($url ? "\nAI 분석용 이슈를 만들었습니다." : "\n(이슈는 만들지 않음: 중복이거나 하루 상한 도달)"),
            $url ?? config('app.url'),
        ));
    }

    /**
     * First line only, bound values removed for SQL errors, masked, then truncated.
     */
    public static function safeMessage(Throwable $e): string
    {
        if ($e instanceof QueryException) {
            $driverMessage = strtok((string) $e->getPrevious()?->getMessage(), "\n") ?: '';
            // Driver messages quote offending values ("Duplicate entry 'x'"); drop them.
            $driverMessage = (string) preg_replace("/'[^']*'|\"[^\"]*\"/", "'[value]'", $driverMessage);
            $message = $driverMessage.' | SQL: '.$e->getSql();
        } else {
            $message = strtok($e->getMessage(), "\n") ?: '';
        }

        return Str::limit(Masker::mask($message), 500);
    }
}
