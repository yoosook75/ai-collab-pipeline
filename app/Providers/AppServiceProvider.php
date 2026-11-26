<?php

namespace App\Providers;

use App\Ops\DiscordWebhookNotifier;
use App\Ops\LogNotifier;
use App\Ops\OpsNotifier;
use App\Ops\SlackWebhookNotifier;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // bind (not singleton) so the provider follows config at resolve time.
        $this->app->bind(OpsNotifier::class, fn () => match (config('ops.notifier')) {
            'discord' => new DiscordWebhookNotifier(config('ops.discord_webhook'), (string) config('ops.app_env_label')),
            'slack' => new SlackWebhookNotifier(config('ops.slack_webhook'), (string) config('ops.app_env_label')),
            default => new LogNotifier,
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
