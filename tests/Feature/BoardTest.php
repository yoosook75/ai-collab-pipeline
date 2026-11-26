<?php

use App\Models\Post;
use App\Models\User;

it('lists only visible posts', function () {
    Post::factory()->create(['title' => 'shown']);
    Post::factory()->create(['title' => 'hidden', 'hidden_at' => now()]);

    $this->get('/')->assertOk()->assertSee('shown')->assertDontSee('hidden');
});

it('paginates posts 10 per page, newest first', function () {
    foreach (range(1, 11) as $i) {
        Post::factory()->create([
            'title' => sprintf('post-%02d', $i),
            'created_at' => now()->subMinutes(20 - $i),
        ]);
    }

    $this->get('/')->assertOk()
        ->assertSee('post-11')
        ->assertDontSee('post-01')
        ->assertSee('?page=2', false);

    $this->get('/?page=2')->assertOk()
        ->assertSee('post-01')
        ->assertDontSee('post-02');
});

it('does not show page links for 10 posts or fewer', function () {
    Post::factory()->count(10)->create();

    $this->get('/')->assertOk()->assertDontSee('?page=2', false);
});

it('does not count hidden posts in page count', function () {
    Post::factory()->count(10)->create();
    Post::factory()->count(3)->create(['hidden_at' => now()]);

    $this->get('/')->assertOk()->assertDontSee('?page=2', false);
    $this->get('/?page=2')->assertOk()->assertSee('아직 글이 없습니다.');
});

it('requires verified email to post', function () {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)->post('/posts', ['title' => 't', 'body' => 'b'])
        ->assertRedirect(route('verification.notice'));
});

it('verified user can post', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/posts', ['title' => 't', 'body' => 'b'])->assertRedirect('/');

    expect(Post::first()->body_hash)->toBe(hash('sha256', 'b'));
});

it('registers and logs in', function () {
    $this->post('/register', [
        'name' => 'a', 'email' => 'a@x.test',
        'password' => 'Passw0rd!x', 'password_confirmation' => 'Passw0rd!x',
    ])->assertRedirect();
    $this->post('/logout');

    $this->post('/login', ['email' => 'a@x.test', 'password' => 'Passw0rd!x'])->assertRedirect('/');
    $this->assertAuthenticated();
});

it('rejects non-utf8 title or body with 422 and stores nothing', function () {
    $user = User::factory()->create();
    $bad = mb_convert_encoding('안녕', 'CP949', 'UTF-8');

    $this->actingAs($user)->withHeader('Accept', 'application/json')->post('/posts', ['title' => $bad, 'body' => '정상 본문'])->assertStatus(422);
    $this->actingAs($user)->withHeader('Accept', 'application/json')->post('/posts', ['title' => '정상 제목', 'body' => "\xBD\xC3"])->assertStatus(422);

    expect(Post::count())->toBe(0);
});

it('still stores valid korean utf-8 posts', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/posts', ['title' => '안녕하세요', 'body' => '한글 본문입니다'])->assertRedirect(route('posts.index'));

    expect(Post::where('title', '안녕하세요')->exists())->toBeTrue();
});
