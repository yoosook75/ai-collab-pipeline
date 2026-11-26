<?php

return [
    'login' => [
        'max_failures' => 5,
        'window_minutes' => 15,
    ],
    'unlock' => [
        'code_ttl_minutes' => 15,
        'max_attempts' => 5,
    ],
    'spam' => [
        'max_posts' => 5,
        'window_minutes' => 10,
        'max_same_body' => 3,
        'max_same_link' => 3,
    ],
];
