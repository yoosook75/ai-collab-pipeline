<?php

namespace App\Security;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class IncidentReporter
{
    /**
     * @param  array{fingerprint: string, class: string, message: string, file: string, line: int, count: int, environment: string, first_seen: string, last_seen: string}  $payload
     * @return string|null URL of the created issue
     */
    public function report(array $payload): ?string
    {
        $repo = config('ops.github.repo');
        $token = config('ops.github.token');
        if (! $repo || ! $token) {
            return null;
        }

        $dedupeKey = 'incident:fp:'.$payload['fingerprint'];
        if (Cache::has($dedupeKey)) {
            return null;
        }

        $dayKey = 'incident:day:'.now()->toDateString();
        if ((int) Cache::get($dayKey, 0) >= (int) config('security.errors.daily_issue_cap')) {
            return null;
        }

        try {
            $response = Http::timeout(10)->withToken((string) $token)
                ->acceptJson()
                ->post("https://api.github.com/repos/{$repo}/issues", [
                    'title' => "[incident] {$payload['class']} at {$payload['file']}:{$payload['line']}",
                    'labels' => ['incident'],
                    'body' => $this->body($payload),
                ]);
        } catch (Throwable $e) {
            Log::warning('incident issue creation failed', ['error' => $e->getMessage()]);

            return null;
        }

        if ($response->failed()) {
            Log::warning('incident issue creation failed', ['status' => $response->status()]);

            return null;
        }

        Cache::put($dedupeKey, true, now()->addHours((int) config('security.errors.dedupe_hours')));
        Cache::put($dayKey, (int) Cache::get($dayKey, 0) + 1, now()->endOfDay());

        return $response->json('html_url');
    }

    /** @param  array<string, string|int>  $p */
    private function body(array $p): string
    {
        $message = Masker::mask((string) $p['message']);

        return <<<MD
        운영 환경에서 같은 오류가 짧은 시간에 반복되어 자동으로 만든 이슈입니다.
        개인정보와 비밀값은 가림 처리했고, 원본 스택트레이스와 요청 데이터는 포함하지 않았습니다.

        | 항목 | 값 |
        |---|---|
        | 환경 | {$p['environment']} |
        | 예외 | `{$p['class']}` |
        | 위치 | `{$p['file']}:{$p['line']}` |
        | 횟수 | {$p['count']}회 |
        | 처음 발생 | {$p['first_seen']} |
        | 마지막 발생 | {$p['last_seen']} |

        **메시지(가림 처리됨)**

        ```
        {$message}
        ```

        <!-- acp-incident:{$p['fingerprint']} -->
        MD;
    }
}
