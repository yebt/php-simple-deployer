<?php

declare(strict_types=1);

use Sphpd\Core\Config;

test('Config: returns default for missing key', function () {
    $config = new Config([]);

    expect($config->get('instructions'))->toBe('deploy.json');
    expect($config->get('logs_path'))->toBe('./logs');
    expect($config->get('webhook_method'))->toBe('POST');
    expect($config->get('dashboard_route'))->toBe('health');
});

test('Config: env value overrides default', function () {
    $config = new Config(['INSTRUCTIONS_FILE' => 'custom.json']);

    expect($config->get('instructions'))->toBe('custom.json');
});

test('Config: null default for optional keys', function () {
    $config = new Config([]);

    expect($config->get('project_path'))->toBeNull();
    expect($config->get('thread_id'))->toBeNull();
});

test('Config: string "true" is cast to boolean true', function () {
    $config = new Config(['TELEGRAM_NOTIFICATIONS' => 'true']);

    expect($config->get('telegram_enabled'))->toBeTrue();
});

test('Config: string "false" is cast to boolean false', function () {
    $config = new Config(['TELEGRAM_NOTIFICATIONS' => 'false']);

    expect($config->get('telegram_enabled'))->toBeFalse();
});

test('Config: string "null" is cast to null', function () {
    $config = new Config(['TELEGRAM_THREAD_ID' => 'null']);

    expect($config->get('thread_id'))->toBeNull();
});

test('Config: all() returns full config array', function () {
    $config = new Config([]);
    $all = $config->all();

    expect($all)->toBeArray();
    expect($all)->toHaveKey('instructions');
    expect($all)->toHaveKey('secure_token');
});

test('Config: unknown key returns null', function () {
    $config = new Config([]);

    expect($config->get('does_not_exist'))->toBeNull();
});
