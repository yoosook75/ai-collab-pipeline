<?php

use App\Mail\UnlockCodeMail;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

it('unlock request responds identically for unknown email', function () {
    Mail::fake();
    $u = User::factory()->create(['locked_at' => now()]);

    $a = $this->post('/unlock', ['email' => $u->email, 'purpose' => 'login']);
    $b = $this->post('/unlock', ['email' => 'nobody@x.test', 'purpose' => 'login']);

    expect($a->status())->toBe($b->status())
        ->and($a->headers->get('Location'))->toBe($b->headers->get('Location'));
    Mail::assertSent(UnlockCodeMail::class, 1);
});

it('does not send a code to an account that is not locked', function () {
    Mail::fake();
    $u = User::factory()->create();

    $this->post('/unlock', ['email' => $u->email, 'purpose' => 'login']);

    Mail::assertNothingSent();
});

it('correct code unlocks', function () {
    $u = User::factory()->create(['locked_at' => now()]);
    $code = issueKnownCode($u, 'login');

    $this->post('/unlock/verify', ['email' => $u->email, 'purpose' => 'login', 'code' => $code]);

    expect($u->fresh()->locked_at)->toBeNull();
});

it('posting code clears posting restriction only', function () {
    $u = User::factory()->create(['locked_at' => now(), 'posting_restricted_at' => now()]);
    $code = issueKnownCode($u, 'posting');

    $this->post('/unlock/verify', ['email' => $u->email, 'purpose' => 'posting', 'code' => $code]);

    expect($u->fresh()->posting_restricted_at)->toBeNull()
        ->and($u->fresh()->locked_at)->not->toBeNull();
});

it('unlock code invalidated after 5 wrong attempts', function () {
    $u = User::factory()->create(['locked_at' => now()]);
    $code = issueKnownCode($u, 'login');
    $wrong = $code === '000000' ? '111111' : '000000';

    foreach (range(1, 5) as $_) {
        $this->post('/unlock/verify', ['email' => $u->email, 'purpose' => 'login', 'code' => $wrong]);
    }
    $this->post('/unlock/verify', ['email' => $u->email, 'purpose' => 'login', 'code' => $code]);

    expect($u->fresh()->locked_at)->not->toBeNull();
});

it('expired unlock code is rejected', function () {
    $u = User::factory()->create(['locked_at' => now()]);
    $code = issueKnownCode($u, 'login');

    $this->travel(16)->minutes();
    $this->post('/unlock/verify', ['email' => $u->email, 'purpose' => 'login', 'code' => $code]);

    expect($u->fresh()->locked_at)->not->toBeNull();
});

it('issuing a new code invalidates the previous one', function () {
    $u = User::factory()->create(['locked_at' => now()]);
    $first = issueKnownCode($u, 'login');
    $second = issueKnownCode($u, 'login');

    if ($first !== $second) {
        $this->post('/unlock/verify', ['email' => $u->email, 'purpose' => 'login', 'code' => $first]);
        expect($u->fresh()->locked_at)->not->toBeNull();
    }
    $this->post('/unlock/verify', ['email' => $u->email, 'purpose' => 'login', 'code' => $second]);
    expect($u->fresh()->locked_at)->toBeNull();
});

it('unlocking allows login again', function () {
    $u = User::factory()->create(['password' => bcrypt('right-pass')]);
    foreach (range(1, 5) as $_) {
        $this->post('/login', ['email' => $u->email, 'password' => 'wrong']);
    }
    $code = issueKnownCode($u->fresh(), 'login');
    $this->post('/unlock/verify', ['email' => $u->email, 'purpose' => 'login', 'code' => $code]);

    $this->post('/login', ['email' => $u->email, 'password' => 'right-pass'])->assertRedirect('/');
    $this->assertAuthenticated();
});
