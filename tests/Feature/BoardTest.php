<?php

use App\Models\Post;
use App\Models\User;

it('lists only visible posts', function () {
    Post::factory()->create(['title' => 'shown']);
    Post::factory()->create(['title' => 'hidden', 'hidden_at' => now()]);

    $this->get('/')->assertOk()->assertSee('shown')->assertDontSee('hidden');
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
