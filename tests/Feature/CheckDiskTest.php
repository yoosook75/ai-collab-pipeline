<?php

use App\Ops\DiskUsage;

it('warns when disk free below 10 percent', function () {
    $ops = fakeOpsNotifier();
    app()->instance(DiskUsage::class, new class extends DiskUsage
    {
        public function freeRatio(string $path): float
        {
            return 0.09;
        }
    });

    $this->artisan('ops:check-disk')->assertSuccessful();

    expect($ops->events)->toHaveCount(1)->and($ops->events[0]->title)->toBe('디스크 부족');
});

it('stays quiet when disk has room', function () {
    $ops = fakeOpsNotifier();
    app()->instance(DiskUsage::class, new class extends DiskUsage
    {
        public function freeRatio(string $path): float
        {
            return 0.5;
        }
    });

    $this->artisan('ops:check-disk')->assertSuccessful();

    expect($ops->events)->toBeEmpty();
});

it('disk alert links to the app', function () {
    $ops = fakeOpsNotifier();
    app()->instance(DiskUsage::class, new class extends DiskUsage
    {
        public function freeRatio(string $path): float
        {
            return 0.01;
        }
    });

    $this->artisan('ops:check-disk');

    expect($ops->events[0]->url)->toBe(config('app.url'));
});
