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
];
