<?php

namespace App\Ops;

use Illuminate\Support\Facades\Log;

final class LogNotifier implements OpsNotifier
{
    public function send(OpsEvent $event): void
    {
        Log::info('ops event', (array) $event);
    }
}
