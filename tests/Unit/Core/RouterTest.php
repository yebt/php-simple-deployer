<?php

declare(strict_types=1);

use Sphpd\Core\Router;

// --- helpers -----------------------------------------------------------------

function makeRouter(): Router
{
    return new Router();
}

// --- add() / resolve() -------------------------------------------------------

test('resolves exact path', function (): void {
    $router = makeRouter();
    $called = false;
    $router->add('/health', function () use (&$called): void {
        $called = true;
    });
    $router->resolve('/health');
    expect($called)->toBeTrue();
});

test('resolves path with capture group', function (): void {
    $router = makeRouter();
    $captured = null;
    $router->add('/log/view/([a-z0-9]+)', function (string $id) use (&$captured): void {
        $captured = $id;
    });
    $router->resolve('/log/view/abc123');
    expect($captured)->toBe('abc123');
});

test('resolves path with multiple capture groups', function (): void {
    $router = makeRouter();
    $captured = [];
    $router->add('/user/([0-9]+)/post/([0-9]+)', function (string $uid, string $pid) use (&$captured): void {
        $captured = [$uid, $pid];
    });
    $router->resolve('/user/7/post/42');
    expect($captured)->toBe(['7', '42']);
});

test('returns value from handler', function (): void {
    $router = makeRouter();
    $router->add('/ping', function (): string {
        return 'pong';
    });
    $result = $router->resolve('/ping');
    expect($result)->toBe('pong');
});

test('strips query string from URI before matching', function (): void {
    $router = makeRouter();
    $called = false;
    $router->add('/deploy', function () use (&$called): void {
        $called = true;
    });
    $router->resolve('/deploy?manual=1');
    expect($called)->toBeTrue();
});

// --- 404 handling ------------------------------------------------------------

test('calls custom not-found handler when no route matches', function (): void {
    $router = makeRouter();
    $hit = false;
    $router->setNotFound(function () use (&$hit): void {
        $hit = true;
    });
    $router->resolve('/does-not-exist');
    expect($hit)->toBeTrue();
});

test('does not call not-found handler when route matches', function (): void {
    $router = makeRouter();
    $hit = false;
    $router->setNotFound(function () use (&$hit): void {
        $hit = true;
    });
    $router->add('/exists', function (): void {});
    $router->resolve('/exists');
    expect($hit)->toBeFalse();
});

test('when two different patterns match, the first registered wins', function (): void {
    $router = makeRouter();
    $log = [];
    // Both patterns match '/foo', but registered at different keys
    $router->add('/foo', function () use (&$log): void {
        $log[] = 'exact';
    });
    $router->add('/f[a-z]+', function () use (&$log): void {
        $log[] = 'regex';
    });
    $router->resolve('/foo');
    expect($log)->toBe(['exact']);
});

test('partial path does not match route anchored to full path', function (): void {
    $router = makeRouter();
    $called = false;
    $router->add('/foo', function () use (&$called): void {
        $called = true;
    });
    $hit404 = false;
    $router->setNotFound(function () use (&$hit404): void {
        $hit404 = true;
    });
    $router->resolve('/foobar');
    expect($called)->toBeFalse();
    expect($hit404)->toBeTrue();
});
