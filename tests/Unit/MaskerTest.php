<?php

use App\Security\Masker;

it('masker removes emails ips and tokens', function () {
    $secretHex = str_repeat('a1', 20);
    $out = Masker::mask("user a.b@x.com from 203.0.113.9 and 2001:db8::1 Bearer abc.def password=hunter2 key={$secretHex}");

    expect($out)->not->toContain('a.b@x.com')
        ->not->toContain('203.0.113.9')
        ->not->toContain('2001:db8::1')
        ->not->toContain('abc.def')
        ->not->toContain('hunter2')
        ->not->toContain($secretHex)
        ->toContain('[email]')->toContain('[ip]')->toContain('[secret]');
});

it('masker keeps ordinary text', function () {
    expect(Masker::mask('Undefined index: title in PostController'))->toBe('Undefined index: title in PostController');
});

it('masker hides long base64 like blobs', function () {
    $blob = base64_encode(random_bytes(40));

    expect(Masker::mask("token {$blob} end"))->not->toContain($blob);
});
