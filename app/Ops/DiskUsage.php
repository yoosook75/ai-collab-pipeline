<?php

namespace App\Ops;

class DiskUsage
{
    public function freeRatio(string $path): float
    {
        $total = disk_total_space($path);
        $free = disk_free_space($path);

        return ($total && $free !== false) ? $free / $total : 1.0;
    }
}
