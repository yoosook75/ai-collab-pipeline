<?php

namespace App\Ops;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

abstract class WebhookNotifier implements OpsNotifier
{
    public function __construct(protected ?string $webhook, protected string $envLabel) {}

    /** @return array<string, mixed> */
    abstract protected function payload(OpsEvent $event): array;

    public function send(OpsEvent $event): void
    {
        if (! $this->webhook) {
            Log::warning('ops notifier webhook missing', ['title' => $event->title]);

            return;
        }

        try {
            $response = Http::timeout(5)->asJson()->post($this->webhook, $this->payload($event));
            if ($response->failed()) {
                Log::warning('ops notifier webhook failed', ['status' => $response->status(), 'title' => $event->title]);
            }
        } catch (Throwable $e) {
            Log::warning('ops notifier webhook error', ['error' => $e->getMessage(), 'title' => $event->title]);
        }
    }

    protected function title(OpsEvent $event): string
    {
        return "[{$this->envLabel}] {$event->title}";
    }
}
