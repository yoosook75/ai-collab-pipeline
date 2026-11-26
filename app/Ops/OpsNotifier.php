<?php

namespace App\Ops;

interface OpsNotifier
{
    public function send(OpsEvent $event): void;
}
