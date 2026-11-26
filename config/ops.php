<?php

return [
    // discord | slack | log
    'notifier' => env('OPS_NOTIFIER', 'log'),
    'discord_webhook' => env('OPS_DISCORD_WEBHOOK'),
    'slack_webhook' => env('OPS_SLACK_WEBHOOK'),
    'app_env_label' => env('APP_ENV_LABEL', env('APP_ENV', 'local')),
];
