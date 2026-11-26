<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $ip
 * @property string $reason
 * @property Carbon $expires_at
 */
#[Fillable(['ip', 'reason', 'expires_at'])]
class BlockedIp extends Model
{
    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }
}
