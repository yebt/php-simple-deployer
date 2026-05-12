<?php

declare(strict_types=1);

use Sphpd\Domain\Notifier\TelegramNotifier;

// ── send() ────────────────────────────────────────────────────────────────────

test('TelegramNotifier: returns false when bot token is empty', function () {
    $notifier = new TelegramNotifier('', 'chat1', null, '/tmp');

    expect($notifier->send('hello'))->toBeFalse();
});

test('TelegramNotifier: returns true on HTTP 200 from transport', function () {
    $transport = function (string $url, array $payload): array {
        return ['code' => 200, 'body' => '{"ok":true}', 'error' => ''];
    };

    $notifier = new TelegramNotifier('token123', 'chat1', null, '/tmp', $transport);

    expect($notifier->send('hello'))->toBeTrue();
});

test('TelegramNotifier: returns false and logs on non-200 response', function () {
    $logDir = sys_get_temp_dir().'/sphpd_tg_test_'.uniqid();
    mkdir($logDir);

    $transport = function (string $url, array $payload): array {
        return ['code' => 500, 'body' => 'error', 'error' => 'timeout'];
    };

    $notifier = new TelegramNotifier('token123', 'chat1', null, $logDir, $transport);
    $result   = $notifier->send('hello');

    expect($result)->toBeFalse();
    expect(file_exists($logDir.'/telegram_errors.log'))->toBeTrue();

    // cleanup
    unlink($logDir.'/telegram_errors.log');
    rmdir($logDir);
});

test('TelegramNotifier: creates log directory if it does not exist on error', function () {
    $logDir = sys_get_temp_dir().'/sphpd_tg_mkdir_'.uniqid();

    expect(is_dir($logDir))->toBeFalse();

    $transport = function (string $url, array $payload): array {
        return ['code' => 500, 'body' => 'error', 'error' => 'timeout'];
    };

    $notifier = new TelegramNotifier('token123', 'chat1', null, $logDir, $transport);
    $notifier->send('hello');

    expect(is_dir($logDir))->toBeTrue();
    expect(file_exists($logDir.'/telegram_errors.log'))->toBeTrue();

    // cleanup
    unlink($logDir.'/telegram_errors.log');
    rmdir($logDir);
});

test('TelegramNotifier: thread_id is included in payload when set', function () {
    $captured = [];
    $transport = function (string $url, array $payload) use (&$captured): array {
        $captured = $payload;
        return ['code' => 200, 'body' => '{"ok":true}', 'error' => ''];
    };

    $notifier = new TelegramNotifier('token', 'chat1', '42', '/tmp', $transport);
    $notifier->send('msg');

    expect($captured)->toHaveKey('message_thread_id');
    expect($captured['message_thread_id'])->toBe('42');
});

test('TelegramNotifier: thread_id is omitted when null', function () {
    $captured = [];
    $transport = function (string $url, array $payload) use (&$captured): array {
        $captured = $payload;
        return ['code' => 200, 'body' => '{"ok":true}', 'error' => ''];
    };

    $notifier = new TelegramNotifier('token', 'chat1', null, '/tmp', $transport);
    $notifier->send('msg');

    expect($captured)->not->toHaveKey('message_thread_id');
});

// ── escapeMarkdown() ──────────────────────────────────────────────────────────

test('TelegramNotifier: escapeMarkdown escapes special MarkdownV2 chars', function () {
    $notifier = new TelegramNotifier('t', 'c', null, '/tmp');

    $result = $notifier->escapeMarkdown('hello_world.test');

    expect($result)->toBe('hello\_world\.test');
});

test('TelegramNotifier: escapeMarkdown leaves plain text untouched', function () {
    $notifier = new TelegramNotifier('t', 'c', null, '/tmp');

    expect($notifier->escapeMarkdown('hello world'))->toBe('hello world');
});

test('TelegramNotifier: escapeMarkdown escapes dash in date format', function () {
    $notifier = new TelegramNotifier('t', 'c', null, '/tmp');

    // Telegram MarkdownV2 rejects unescaped '-' in italic/text context.
    $result = $notifier->escapeMarkdown('2026-05-12 10:56:18');

    expect($result)->toBe('2026\-05\-12 10:56:18');
    expect($result)->not->toContain('--');
});

// ── buildReport() ────────────────────────────────────────────────────────────

test('TelegramNotifier: buildReport contains SUCCESS emoji and status', function () {
    $notifier = new TelegramNotifier('t', 'c', null, '/tmp');
    $report   = $notifier->buildReport('myapp', true, 1.5, 'http://example.com/log');

    expect($report)->toContain('✅');
    expect($report)->toContain('SUCCESS');
});

test('TelegramNotifier: buildReport contains FAILED and task name on failure', function () {
    $notifier = new TelegramNotifier('t', 'c', null, '/tmp');
    $report   = $notifier->buildReport('myapp', false, 3.0, 'http://x.com', 'Build step');

    expect($report)->toContain('❌');
    expect($report)->toContain('FAILED');
    expect($report)->toContain('Build step');
});
