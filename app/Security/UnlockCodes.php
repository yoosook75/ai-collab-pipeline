<?php

namespace App\Security;

use App\Mail\UnlockCodeMail;
use App\Models\UnlockCode;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;

class UnlockCodes
{
    public const PURPOSES = ['login', 'posting'];

    public function issue(User $user, string $purpose): void
    {
        $this->assertPurpose($purpose);

        UnlockCode::where('user_id', $user->id)->where('purpose', $purpose)
            ->whereNull('used_at')->update(['used_at' => now()]);

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        UnlockCode::create([
            'user_id' => $user->id,
            'purpose' => $purpose,
            'code_hash' => $this->hash($code),
            'expires_at' => now()->addMinutes((int) config('security.unlock.code_ttl_minutes')),
        ]);

        Mail::to($user)->send(new UnlockCodeMail($code, $purpose));
    }

    public function verify(User $user, string $purpose, string $code): bool
    {
        $this->assertPurpose($purpose);

        $record = UnlockCode::where('user_id', $user->id)->where('purpose', $purpose)
            ->whereNull('used_at')->where('expires_at', '>', now())
            ->latest('id')->first();

        if (! $record) {
            return false;
        }

        if (! hash_equals($record->code_hash, $this->hash($code))) {
            $record->attempts++;
            if ($record->attempts >= (int) config('security.unlock.max_attempts')) {
                $record->used_at = now();
            }
            $record->save();

            return false;
        }

        $record->update(['used_at' => now()]);

        if ($purpose === 'login') {
            $user->forceFill(['locked_at' => null])->save();
            app(LoginLockout::class)->clear($user->email);
        } else {
            $user->forceFill(['posting_restricted_at' => null])->save();
        }

        return true;
    }

    private function hash(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }

    private function assertPurpose(string $purpose): void
    {
        if (! in_array($purpose, self::PURPOSES, true)) {
            throw new InvalidArgumentException("Unknown unlock purpose [{$purpose}]");
        }
    }
}
