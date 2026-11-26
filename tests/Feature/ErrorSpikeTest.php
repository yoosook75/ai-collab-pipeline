<?php

use App\Security\ErrorSpikeMonitor;
use App\Security\IncidentReporter;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Http::fake(['api.github.com/*' => Http::response(['html_url' => 'https://github.com/o/r/issues/9'], 201)]);
    config(['ops.github.repo' => 'o/r', 'ops.github.token' => 't']);
});

function spike(Throwable $e, int $times): void
{
    foreach (range(1, $times) as $_) {
        app(ErrorSpikeMonitor::class)->record($e);
    }
}

it('creates one incident issue after threshold', function () {
    $ops = fakeOpsNotifier();

    spike(new RuntimeException('boom for a.b@x.com'), 12);

    Http::assertSentCount(1);
    Http::assertSent(fn ($r) => $r->url() === 'https://api.github.com/repos/o/r/issues'
        && $r->hasHeader('Authorization', 'Bearer t')
        && str_contains($r['body'], '[email]')
        && ! str_contains($r['body'], 'a.b@x.com')
        && str_contains($r['body'], '<!-- acp-incident:')
        && str_starts_with($r['title'], '[incident] RuntimeException at ')
        && in_array('incident', $r['labels'], true));
    expect($ops->events)->toHaveCount(1)
        ->and($ops->events[0]->level)->toBe('critical')
        ->and($ops->events[0]->url)->toBe('https://github.com/o/r/issues/9');
});

it('does nothing below threshold', function () {
    spike(new RuntimeException('x'), 9);

    Http::assertNothingSent();
});

it('does not recreate within dedupe window', function () {
    $e = new RuntimeException('same');
    spike($e, 10);
    $this->travel(6)->minutes();
    spike($e, 10);

    Http::assertSentCount(1);
});

it('recreates after dedupe window', function () {
    $e = new RuntimeException('same');
    spike($e, 10);
    $this->travel(25)->hours();
    spike($e, 10);

    Http::assertSentCount(2);
});

it('respects daily issue cap', function () {
    $ops = fakeOpsNotifier();
    foreach (range(1, 6) as $i) {
        app(IncidentReporter::class)->report([
            'fingerprint' => "fp{$i}", 'class' => 'RuntimeException', 'message' => 'm',
            'file' => 'app/X.php', 'line' => $i, 'count' => 10, 'environment' => 'staging',
            'first_seen' => now()->toIso8601String(), 'last_seen' => now()->toIso8601String(),
        ]);
    }

    Http::assertSentCount(5);
});

it('skips github when not configured', function () {
    config(['ops.github.repo' => null]);

    spike(new RuntimeException('x'), 10);

    Http::assertNothingSent();
});

it('exceptions thrown in requests reach the spike monitor', function () {
    Route::middleware('web')->get('/__boom', fn () => throw new LogicException('kaboom'));

    foreach (range(1, 10) as $_) {
        $this->get('/__boom')->assertServerError();
    }

    Http::assertSentCount(1);
});
