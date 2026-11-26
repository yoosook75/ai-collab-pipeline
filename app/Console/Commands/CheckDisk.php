<?php

namespace App\Console\Commands;

use App\Ops\DiskUsage;
use App\Ops\OpsEvent;
use App\Ops\OpsNotifier;
use Illuminate\Console\Command;

class CheckDisk extends Command
{
    protected $signature = 'ops:check-disk';

    protected $description = 'Alert when free disk space drops below the configured ratio';

    public function handle(DiskUsage $disk, OpsNotifier $notifier): int
    {
        $path = (string) config('security.disk.path');
        $ratio = $disk->freeRatio($path);
        $min = (float) config('security.disk.min_free_ratio');

        if ($ratio < $min) {
            $notifier->send(new OpsEvent(
                '디스크 부족',
                'critical',
                sprintf('%s 여유 공간이 %.1f%%입니다 (기준 %.0f%%).', $path, $ratio * 100, $min * 100),
            ));
        }

        return self::SUCCESS;
    }
}
