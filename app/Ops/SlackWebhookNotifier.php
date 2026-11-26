<?php

namespace App\Ops;

final class SlackWebhookNotifier extends WebhookNotifier
{
    private const ICONS = ['info' => ':information_source:', 'warn' => ':warning:', 'critical' => ':rotating_light:'];

    protected function payload(OpsEvent $event): array
    {
        $text = self::ICONS[$event->level].' *'.$this->title($event)."*\n".$event->summary;
        foreach ($event->fields as $name => $value) {
            $text .= "\n• {$name}: {$value}";
        }
        if ($event->url) {
            $text .= "\n<{$event->url}|열기>";
        }

        return ['text' => $this->title($event), 'blocks' => [['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => $text]]]];
    }
}
