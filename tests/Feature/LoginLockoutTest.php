<?php

use App\Models\User;

it('locks account after 5 failures and notifies', function () {
    $ops = fakeOpsNotifier();
    $u = User::factory()->create(['password' => bcrypt('right-pass')]);

    foreach (range(1, 5) as $_) {
        $this->post('/login', ['email' => $u->email, 'password' => 'wrong']);
    }

    expect($u->fresh()->locked_at)->not->toBeNull();
    expect($ops->events)->toHaveCount(1)
        ->and($ops->events[0]->title)->toBe('계정 잠금');

    $this->post('/login', ['email' => $u->email, 'password' => 'right-pass'])->assertSessionHasErrors('email');
    $this->assertGuest();
});

it('does not lock before reaching the threshold', function () {
    $u = User::factory()->create();

    foreach (range(1, 4) as $_) {
        $this->post('/login', ['email' => $u->email, 'password' => 'wrong']);
    }

    expect($u->fresh()->locked_at)->toBeNull();
});

it('admin is never locked', function () {
    $u = User::factory()->create(['is_admin' => true]);

    foreach (range(1, 10) as $_) {
        $this->post('/login', ['email' => $u->email, 'password' => 'wrong']);
    }

    expect($u->fresh()->locked_at)->toBeNull();
});

it('locked login error points to unlock page', function () {
    $u = User::factory()->create(['password' => bcrypt('right-pass'), 'locked_at' => now()]);

    $this->post('/login', ['email' => $u->email, 'password' => 'right-pass']);

    $this->get('/login')->assertSee(route('unlock.request', ['purpose' => 'login']), false);
});

it('throttles admin password guessing without locking', function () {
    $u = User::factory()->create(['is_admin' => true, 'password' => bcrypt('right-pass')]);

    foreach (range(1, 5) as $_) {
        $this->post('/login', ['email' => $u->email, 'password' => 'wrong']);
    }

    $this->post('/login', ['email' => $u->email, 'password' => 'right-pass'])->assertStatus(429)->assertSee('1분 뒤에 다시 시도하세요');
    expect($u->fresh()->locked_at)->toBeNull();

    $this->travel(61)->seconds();
    $this->post('/login', ['email' => $u->email, 'password' => 'right-pass'])->assertRedirect('/');
});

it('lock alert links to the app', function () {
    $ops = fakeOpsNotifier();
    $u = User::factory()->create();

    foreach (range(1, 5) as $_) {
        $this->post('/login', ['email' => $u->email, 'password' => 'wrong']);
    }

    expect($ops->events[0]->url)->toBe(config('app.url'));
});
