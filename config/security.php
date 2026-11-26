<?php

return [
    'login' => [
        'max_failures' => 5,
        'window_minutes' => 15,
        'throttle_per_minute' => 5,
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
    'ip' => [
        'probe_paths' => ['.env', 'wp-admin', 'wp-login.php', 'phpmyadmin', '.git/config', 'xmlrpc.php'],
        'probe_block_minutes' => 60,
        'max_requests' => 300,
        'request_window_minutes' => 5,
        'flood_block_minutes' => 30,
        // Comma-separated CIDRs of the reverse proxy network (acp-edge). Empty = trust nobody.
        'trusted_proxies' => env('TRUSTED_PROXIES', ''),
    ],
    'errors' => [
        'threshold' => 10,
        'window_minutes' => 5,
        'dedupe_hours' => 24,
        'daily_issue_cap' => 5,
    ],
    'disk' => [
        'min_free_ratio' => 0.10,
        'path' => '/',
    ],
];
