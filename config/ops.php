<?php

return [
    // discord | slack | log
    'notifier' => env('OPS_NOTIFIER', 'log'),
    'discord_webhook' => env('OPS_DISCORD_WEBHOOK'),
    'slack_webhook' => env('OPS_SLACK_WEBHOOK'),
    'app_env_label' => env('APP_ENV_LABEL', env('APP_ENV', 'local')),
    'github' => [
        // owner/repo; token is a fine-grained PAT limited to Issues: write on this repo.
        'repo' => env('GITHUB_REPO'),
        'token' => env('GITHUB_INCIDENT_TOKEN'),
    ],
];
