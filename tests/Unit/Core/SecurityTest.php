<?php

declare(strict_types=1);

use Sphpd\Core\Security;

test('Security: no token configured allows any request', function () {
    $security = new Security('');

    expect($security->isAuthorised(['x-deploy-token' => 'whatever']))->toBeTrue();
});

test('Security: valid token in header is authorised', function () {
    $security = new Security('secret123');

    expect($security->isAuthorised(['x-deploy-token' => 'secret123']))->toBeTrue();
});

test('Security: token header is case-insensitive', function () {
    $security = new Security('secret123');

    expect($security->isAuthorised(['X-Deploy-Token' => 'secret123']))->toBeTrue();
    expect($security->isAuthorised(['X-DEPLOY-TOKEN' => 'secret123']))->toBeTrue();
});

test('Security: valid token in query string is authorised', function () {
    $security = new Security('secret123');

    expect($security->isAuthorised([], ['token' => 'secret123']))->toBeTrue();
});

test('Security: invalid token is rejected', function () {
    $security = new Security('secret123');

    expect($security->isAuthorised(['x-deploy-token' => 'wrong']))->toBeFalse();
});

test('Security: missing token is rejected', function () {
    $security = new Security('secret123');

    expect($security->isAuthorised([]))->toBeFalse();
});

test('Security: localhost is always allowed regardless of token', function () {
    $security = new Security('secret123');

    expect($security->isAuthorised([], [], '127.0.0.1'))->toBeTrue();
    expect($security->isAuthorised([], [], '::1'))->toBeTrue();
    expect($security->isAuthorised([], [], 'localhost'))->toBeTrue();
});

test('Security: manual flag bypasses token check', function () {
    $security = new Security('secret123');

    expect($security->isAuthorised([], [], '1.2.3.4', true))->toBeTrue();
});

test('Security: headersFromServer normalises HTTP_ prefixed keys', function () {
    $server = ['HTTP_X_DEPLOY_TOKEN' => 'abc', 'HTTP_ACCEPT' => 'application/json'];
    $headers = Security::headersFromServer($server);

    expect($headers)->toHaveKey('X-Deploy-Token');
    expect($headers['X-Deploy-Token'])->toBe('abc');
});
