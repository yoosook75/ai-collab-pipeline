<?php

use App\Models\User;
use App\Security\IpBlocker;
use Illuminate\Support\Facades\URL;

it('blocks probe path without a per-event alert (daily .env summary covers it)', function () {
    $ops = fakeOpsNotifier();

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->get('/.env')->assertForbidden();
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->get('/')->assertForbidden();

    expect($ops->events)->toBeEmpty();
});

it('probe block alerts can be turned back on by config', function () {
    config(['security.ip.notify_probe_blocks' => true]);
    $ops = fakeOpsNotifier();

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->get('/.env')->assertForbidden();

    expect($ops->events)->toHaveCount(1)
        ->and($ops->events[0]->url)->toContain('/ops/unblock/203.0.113.9')->toContain('signature=');
});

it('flood block still alerts immediately with signed unblock link', function () {
    config(['security.ip.max_requests' => 3]);
    $ops = fakeOpsNotifier();

    foreach (range(1, 4) as $_) {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->get('/');
    }

    expect($ops->events)->toHaveCount(1)
        ->and($ops->events[0]->url)->toContain('/ops/unblock/203.0.113.9')->toContain('signature=');
});

it('does not block normal visitors', function () {
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->get('/')->assertOk();
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->get('/no-such-page')->assertNotFound();

    expect(app(IpBlocker::class)->isBlocked('203.0.113.9'))->toBeFalse();
});

it('blocks an ip that floods requests', function () {
    config(['security.ip.max_requests' => 3]);

    foreach (range(1, 3) as $_) {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->get('/')->assertOk();
    }
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->get('/')->assertForbidden();
});

it('does not notify again for an ip that is already blocked', function () {
    $ops = fakeOpsNotifier();

    app(IpBlocker::class)->block('203.0.113.9', 'test', 60);
    app(IpBlocker::class)->block('203.0.113.9', 'test', 60);

    expect($ops->events)->toHaveCount(1);
});

it('signed unblock link removes block', function () {
    app(IpBlocker::class)->block('203.0.113.9', 'test', 60);
    $url = URL::temporarySignedRoute('ops.unblock', now()->addHour(), ['ip' => '203.0.113.9']);

    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.1'])->get($url)->assertOk();

    expect(app(IpBlocker::class)->isBlocked('203.0.113.9'))->toBeFalse();
});

it('signed unblock link works from the blocked ip itself', function () {
    app(IpBlocker::class)->block('203.0.113.9', 'test', 60);
    $url = URL::temporarySignedRoute('ops.unblock', now()->addHour(), ['ip' => '203.0.113.9']);

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->get($url)->assertOk();

    expect(app(IpBlocker::class)->isBlocked('203.0.113.9'))->toBeFalse();
});

it('unsigned unblock is rejected', function () {
    app(IpBlocker::class)->block('203.0.113.9', 'test', 60);

    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.1'])->get('/ops/unblock/203.0.113.9')->assertForbidden();

    expect(app(IpBlocker::class)->isBlocked('203.0.113.9'))->toBeTrue();
});

it('block expires', function () {
    app(IpBlocker::class)->block('203.0.113.9', 'test', 30);

    $this->travel(31)->minutes();

    expect(app(IpBlocker::class)->isBlocked('203.0.113.9'))->toBeFalse();
});

it('logged-in admin passes even from blocked ip', function () {
    app(IpBlocker::class)->block('203.0.113.9', 'test', 60);

    $this->actingAs(User::factory()->create(['is_admin' => true]))
        ->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->get('/')->assertOk();
});

it('spoofed forwarded header from untrusted source is ignored', function () {
    config(['security.ip.trusted_proxies' => '172.30.0.0/16']);

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
        ->get('/.env', ['X-Forwarded-For' => '198.51.100.7']);

    expect(app(IpBlocker::class)->isBlocked('203.0.113.9'))->toBeTrue()
        ->and(app(IpBlocker::class)->isBlocked('198.51.100.7'))->toBeFalse();
});

it('forwarded header from trusted proxy is used', function () {
    config(['security.ip.trusted_proxies' => '172.30.0.0/16']);

    $this->withServerVariables(['REMOTE_ADDR' => '172.30.0.5'])
        ->get('/.env', ['X-Forwarded-For' => '198.51.100.7']);

    expect(app(IpBlocker::class)->isBlocked('198.51.100.7'))->toBeTrue()
        ->and(app(IpBlocker::class)->isBlocked('172.30.0.5'))->toBeFalse();
});
