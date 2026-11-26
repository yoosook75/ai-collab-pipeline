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

it('masker hides env style keys', function () {
    $out = Masker::mask('DB_PASSWORD=hunter2 MAIL_PASSWORD=s3cretpw APP_KEY=abc');

    expect($out)->not->toContain('hunter2')->not->toContain('s3cretpw')->not->toContain('=abc');
});

it('masker hides json and php array secrets', function () {
    expect(Masker::mask('{"password":"hunter2","api_key":"abc123"}'))->not->toContain('hunter2')->not->toContain('abc123');
    expect(Masker::mask("'password' => 'hunter2'"))->not->toContain('hunter2');
});

it('masker hides credentials in urls', function () {
    expect(Masker::mask('mysql://acp:hunter2@db:3306/acp'))->not->toContain('hunter2')->toContain('db:3306');
});

it('masker hides authorization header values', function () {
    expect(Masker::mask('Authorization: token ghp_short'))->not->toContain('ghp_short');
    expect(Masker::mask('leaked ghp_abcDEF123 here'))->not->toContain('ghp_abcDEF123');
});

it('masker replaces configured secret values exactly', function () {
    config(['database.connections.mysql.password' => 'plain-db-pass', 'ops.github.token' => 'gh-token-value']);

    expect(Masker::mask('connect failed with plain-db-pass and gh-token-value'))
        ->not->toContain('plain-db-pass')->not->toContain('gh-token-value');
});
