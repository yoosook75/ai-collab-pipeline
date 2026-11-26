<?php

use App\Ops\OpsNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RecordingOpsNotifier;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature', 'Unit');

function fakeOpsNotifier(): RecordingOpsNotifier
{
    $fake = new RecordingOpsNotifier;
    app()->instance(OpsNotifier::class, $fake);

    return $fake;
}
