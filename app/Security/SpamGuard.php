<?php

namespace App\Security;

use App\Models\Post;
use App\Models\User;
use App\Ops\OpsEvent;
use App\Ops\OpsNotifier;

class SpamGuard
{
    private const URL_PATTERN = '~https?://[^\s<>"\']+~i';

    public function __construct(private OpsNotifier $notifier) {}

    public function inspect(User $user, Post $justCreated): bool
    {
        if ($user->is_admin) {
            return false;
        }

        $cfg = config('security.spam');
        $recent = Post::where('user_id', $user->id)
            ->where('created_at', '>=', now()->subMinutes((int) $cfg['window_minutes']))
            ->get(['id', 'body', 'body_hash']);

        $reason = match (true) {
            $recent->count() > (int) $cfg['max_posts'] => '짧은 시간에 글이 너무 많음',
            $recent->where('body_hash', $justCreated->body_hash)->count() >= (int) $cfg['max_same_body'] => '같은 내용 반복',
            $this->maxLinkRepeats($recent->pluck('body')->all()) >= (int) $cfg['max_same_link'] => '같은 링크 반복',
            default => null,
        };

        if ($reason === null) {
            return false;
        }

        $user->forceFill(['posting_restricted_at' => now()])->save();
        Post::whereIn('id', $recent->pluck('id'))->whereNull('hidden_at')->update(['hidden_at' => now()]);

        $this->notifier->send(new OpsEvent(
            '스팸 의심 — 글쓰기 제한',
            'warn',
            "사용자 #{$user->id}의 글쓰기를 제한하고 최근 글 {$recent->count()}개를 숨겼습니다(삭제 아님). 본인이 이메일 인증으로 해제할 수 있습니다.",
            null,
            ['사용자' => "#{$user->id}", '사유' => $reason],
        ));

        return true;
    }

    /** @param  list<string>  $bodies */
    private function maxLinkRepeats(array $bodies): int
    {
        $counts = [];
        foreach ($bodies as $body) {
            preg_match_all(self::URL_PATTERN, $body, $m);
            foreach (array_unique($m[0]) as $url) {
                $key = mb_strtolower(rtrim($url, '.,)'));
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            }
        }

        return $counts === [] ? 0 : max($counts);
    }
}
