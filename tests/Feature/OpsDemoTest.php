<?php

use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::fake(['api.github.com/*' => Http::response(['html_url' => 'https://github.com/o/r/issues/9'], 201)]);
    config(['ops.github.repo' => 'o/r', 'ops.github.token' => 't']);
});

it('redirects guests to login', function () {
    config(['ops.app_env_label' => 'staging']);

    $this->get(route('ops.demo-error'))->assertRedirect(route('login'));
});

it('returns 404 when not staging', function () {
    config(['ops.app_env_label' => 'production']);

    $this->actingAs(User::factory()->create(['is_admin' => true]))
        ->get(route('ops.demo-error'))
        ->assertNotFound();
});

it('returns 403 for non-admin on staging', function () {
    config(['ops.app_env_label' => 'staging']);

    $this->actingAs(User::factory()->create())
        ->get(route('ops.demo-error'))
        ->assertForbidden();
});

it('throws a 500 for admin on staging and reaches the spike monitor', function () {
    config(['ops.app_env_label' => 'staging']);
    $this->actingAs(User::factory()->create(['is_admin' => true]));

    foreach (range(1, 10) as $_) {
        $this->get(route('ops.demo-error'))->assertServerError();
    }

    Http::assertSentCount(1);
});
