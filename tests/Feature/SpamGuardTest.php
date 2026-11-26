<?php

use App\Models\Post;
use App\Models\User;

it('restricts and hides on burst', function () {
    $ops = fakeOpsNotifier();
    $u = User::factory()->create();

    foreach (range(1, 6) as $i) {
        $this->actingAs($u)->post('/posts', ['title' => "t$i", 'body' => "b$i"]);
    }

    expect($u->fresh()->posting_restricted_at)->not->toBeNull();
    expect(Post::visible()->where('user_id', $u->id)->count())->toBe(0);
    expect(Post::where('user_id', $u->id)->count())->toBe(6);
    expect($ops->events)->toHaveCount(1)
        ->and($ops->events[0]->title)->toBe('스팸 의심 — 글쓰기 제한');
});

it('allows normal posting under the limit', function () {
    $u = User::factory()->create();

    foreach (range(1, 5) as $i) {
        $this->actingAs($u)->post('/posts', ['title' => "t$i", 'body' => "b$i"]);
    }

    expect($u->fresh()->posting_restricted_at)->toBeNull();
    expect(Post::visible()->where('user_id', $u->id)->count())->toBe(5);
});

it('restricts on repeated identical body', function () {
    $u = User::factory()->create();

    foreach (range(1, 3) as $i) {
        $this->actingAs($u)->post('/posts', ['title' => "t$i", 'body' => 'same spam']);
    }

    expect($u->fresh()->posting_restricted_at)->not->toBeNull();
});

it('restricts on repeated link', function () {
    $u = User::factory()->create();

    foreach (range(1, 3) as $i) {
        $this->actingAs($u)->post('/posts', ['title' => "t$i", 'body' => "buy $i https://spam.test/x"]);
    }

    expect($u->fresh()->posting_restricted_at)->not->toBeNull();
});

it('posts outside the window do not count', function () {
    $u = User::factory()->create();
    foreach (range(1, 5) as $i) {
        $this->actingAs($u)->post('/posts', ['title' => "t$i", 'body' => "b$i"]);
    }

    $this->travel(11)->minutes();
    $this->actingAs($u)->post('/posts', ['title' => 't6', 'body' => 'b6']);

    expect($u->fresh()->posting_restricted_at)->toBeNull();
});

it('restricted user is sent to posting unlock', function () {
    $u = User::factory()->create(['posting_restricted_at' => now()]);

    $this->actingAs($u)->post('/posts', ['title' => 't', 'body' => 'b'])
        ->assertRedirectContains('/unlock?purpose=posting');
    expect(Post::count())->toBe(0);
});

it('admin is never restricted', function () {
    $u = User::factory()->create(['is_admin' => true]);

    foreach (range(1, 8) as $i) {
        $this->actingAs($u)->post('/posts', ['title' => "t$i", 'body' => 'same']);
    }

    expect($u->fresh()->posting_restricted_at)->toBeNull();
});
