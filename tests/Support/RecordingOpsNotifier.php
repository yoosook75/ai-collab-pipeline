<?php

namespace Tests\Support;

use App\Ops\OpsEvent;
use App\Ops\OpsNotifier;

final class RecordingOpsNotifier implements OpsNotifier
{
    /** @var list<OpsEvent> */
    public array $events = [];

    public function send(OpsEvent $event): void
    {
        $this->events[] = $event;
    }
}
