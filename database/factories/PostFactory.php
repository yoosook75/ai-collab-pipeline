<?php

namespace Database\Factories;

use App\Models\Post;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Post>
 */
class PostFactory extends Factory
{
    public function definition(): array
    {
        $body = fake()->paragraph();

        return [
            'user_id' => User::factory(),
            'title' => fake()->sentence(4),
            'body' => $body,
            'body_hash' => hash('sha256', $body),
        ];
    }
}
