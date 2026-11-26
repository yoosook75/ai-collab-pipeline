<?php

namespace App\Ops;

final class DiscordWebhookNotifier extends WebhookNotifier
{
    private const COLORS = ['info' => 0x3498DB, 'warn' => 0xF1C40F, 'critical' => 0xE74C3C];

    protected function payload(OpsEvent $event): array
    {
        $embed = [
            'title' => $this->title($event),
            'description' => $event->summary,
            'color' => self::COLORS[$event->level],
            'fields' => array_map(
                fn (string $name, string $value) => ['name' => $name, 'value' => $value, 'inline' => true],
                array_keys($event->fields),
                array_values($event->fields),
            ),
        ];
        if ($event->url) {
            $embed['url'] = $event->url;
        }

        return ['embeds' => [$embed]];
    }
}
