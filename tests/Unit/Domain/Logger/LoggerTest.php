<?php

declare(strict_types=1);

use Sphpd\Domain\Logger\Logger;

// ── buildHtml ────────────────────────────────────────────────────────────────

test('Logger: buildHtml wraps body in valid HTML skeleton', function () {
    $logger = new Logger('/tmp/logs', '/tmp/status.json');
    $html = $logger->buildHtml('Test Title', '<p>content</p>');

    expect($html)->toContain('<!DOCTYPE html>');
    expect($html)->toContain('<title>Test Title</title>');
    expect($html)->toContain('<p>content</p>');
});

test('Logger: buildHtml escapes nothing — caller is responsible for escaping', function () {
    $logger = new Logger('/tmp/logs', '/tmp/status.json');
    $html = $logger->buildHtml('T', '<b>bold</b>');

    expect($html)->toContain('<b>bold</b>');
});

// ── updateLiveStatus ─────────────────────────────────────────────────────────

test('Logger: updateLiveStatus writes JSON to status file', function () {
    $statusFile = tempnam(sys_get_temp_dir(), 'sphpd_status_');
    $logger = new Logger('/tmp/logs', $statusFile);

    $logger->updateLiveStatus(['running' => true, 'task' => 'Build']);

    $written = json_decode(file_get_contents($statusFile), true);
    expect($written['running'])->toBeTrue();
    expect($written['task'])->toBe('Build');

    unlink($statusFile);
});

test('Logger: updateLiveStatus overwrites previous status', function () {
    $statusFile = tempnam(sys_get_temp_dir(), 'sphpd_status_');
    $logger = new Logger('/tmp/logs', $statusFile);

    $logger->updateLiveStatus(['running' => true]);
    $logger->updateLiveStatus(['running' => false, 'finished' => true]);

    $written = json_decode(file_get_contents($statusFile), true);
    expect($written['running'])->toBeFalse();
    expect($written['finished'])->toBeTrue();

    unlink($statusFile);
});

// ── logRequest ───────────────────────────────────────────────────────────────

test('Logger: logRequest writes timestamp and method to file', function () {
    $logFile = tempnam(sys_get_temp_dir(), 'sphpd_req_');
    $logger = new Logger('/tmp/logs', '/tmp/status.json');

    $logger->logRequest(
        $logFile,
        '{"key":"val"}',
        ['REQUEST_METHOD' => 'POST', 'REMOTE_ADDR' => '1.2.3.4'],
    );

    $content = file_get_contents($logFile);
    expect($content)->toContain('"method": "POST"');
    expect($content)->toContain('"remote_addr": "1.2.3.4"');

    unlink($logFile);
});

test('Logger: logRequest appends multiple entries', function () {
    $logFile = tempnam(sys_get_temp_dir(), 'sphpd_req_');
    $logger = new Logger('/tmp/logs', '/tmp/status.json');

    $logger->logRequest($logFile, 'first', ['REQUEST_METHOD' => 'GET']);
    $logger->logRequest($logFile, 'second', ['REQUEST_METHOD' => 'POST']);

    $content = file_get_contents($logFile);
    expect($content)->toContain('"method": "GET"');
    expect($content)->toContain('"method": "POST"');

    unlink($logFile);
});
