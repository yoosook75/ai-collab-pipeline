<?php

use App\Mail\UnlockCodeMail;
use App\Models\User;
use App\Ops\OpsNotifier;
use App\Security\UnlockCodes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
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

function issueKnownCode(User $user, string $purpose): string
{
    Mail::fake();
    app(UnlockCodes::class)->issue($user, $purpose);

    $code = null;
    Mail::assertSent(UnlockCodeMail::class, function (UnlockCodeMail $mail) use (&$code) {
        $code = $mail->code;

        return true;
    });

    return (string) $code;
}
