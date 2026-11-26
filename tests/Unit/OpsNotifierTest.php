<?php

use App\Ops\OpsEvent;
use App\Ops\OpsNotifier;
use Illuminate\Support\Facades\Http;

it('posts discord embed with link', function () {
    Http::fake();
    config(['ops.notifier' => 'discord', 'ops.discord_webhook' => 'https://discord.test/hook', 'ops.app_env_label' => 'staging']);

    app(OpsNotifier::class)->send(new OpsEvent('계정 잠금', 'warn', 'user#3 잠금', 'https://app.test/x'));

    Http::assertSent(fn ($r) => $r->url() === 'https://discord.test/hook'
        && str_contains($r['embeds'][0]['title'], '[staging] 계정 잠금')
        && $r['embeds'][0]['url'] === 'https://app.test/x'
        && $r['embeds'][0]['color'] === 0xF1C40F);
});

it('posts slack blocks when provider is slack', function () {
    Http::fake();
    config(['ops.notifier' => 'slack', 'ops.slack_webhook' => 'https://slack.test/hook']);

    app(OpsNotifier::class)->send(new OpsEvent('t', 'info', 's'));

    Http::assertSent(fn ($r) => $r->url() === 'https://slack.test/hook' && isset($r['blocks']));
});

it('never throws when webhook fails', function () {
    Http::fake(['*' => Http::response('', 500)]);
    config(['ops.notifier' => 'discord', 'ops.discord_webhook' => 'https://discord.test/hook']);

    app(OpsNotifier::class)->send(new OpsEvent('t', 'info', 's'));

    Http::assertSentCount(1);
});

it('log notifier sends nothing over http', function () {
    Http::fake();
    config(['ops.notifier' => 'log']);

    app(OpsNotifier::class)->send(new OpsEvent('t', 'info', 's'));

    Http::assertNothingSent();
});

it('fake ops notifier records events', function () {
    $ops = fakeOpsNotifier();

    app(OpsNotifier::class)->send(new OpsEvent('t', 'info', 's'));

    expect($ops->events)->toHaveCount(1);
});
