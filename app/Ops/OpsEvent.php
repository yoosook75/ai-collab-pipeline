<?php

namespace App\Ops;

final class OpsEvent
{
    /**
     * @param  'info'|'warn'|'critical'  $level
     * @param  array<string, string>  $fields
     */
    public function __construct(
        public string $title,
        public string $level,
        public string $summary,
        public ?string $url = null,
        public array $fields = [],
    ) {}
}
