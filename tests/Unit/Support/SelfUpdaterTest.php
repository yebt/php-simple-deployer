<?php

declare(strict_types=1);

use Sphpd\Support\SelfUpdater;

// ── resolveStatus() ───────────────────────────────────────────────────────────

test('SelfUpdater: has_update is false when hashes match', function () {
    $scriptFile = tempnam(sys_get_temp_dir(), 'sphpd_script_');
    file_put_contents($scriptFile, '<?php echo "v1";');

    $downloader = function () use ($scriptFile): string {
        return file_get_contents($scriptFile); // same content
    };

    $updater = new SelfUpdater('http://example.com', '/tmp', $scriptFile, 300, $downloader);
    $status  = $updater->resolveStatus();

    expect($status['has_update'])->toBeFalse();

    unlink($scriptFile);
    // clean cache
    $cacheFile = sys_get_temp_dir().'/sphpd_self_update_status_'.md5($scriptFile).'.json';
    if (file_exists($cacheFile)) unlink($cacheFile);
});

test('SelfUpdater: has_update is true when hashes differ', function () {
    $scriptFile = tempnam(sys_get_temp_dir(), 'sphpd_script_');
    file_put_contents($scriptFile, '<?php echo "v1";');

    $downloader = function (): string {
        return '<?php echo "v2";'; // different content
    };

    $updater = new SelfUpdater('http://example.com', '/tmp', $scriptFile, 300, $downloader);
    $status  = $updater->resolveStatus();

    expect($status['has_update'])->toBeTrue();

    unlink($scriptFile);
    $cacheFile = sys_get_temp_dir().'/sphpd_self_update_status_'.md5($scriptFile).'.json';
    if (file_exists($cacheFile)) unlink($cacheFile);
});

test('SelfUpdater: returns cached result within TTL without downloading', function () {
    $scriptFile = tempnam(sys_get_temp_dir(), 'sphpd_script_');
    file_put_contents($scriptFile, '<?php // old');

    $callCount  = 0;
    $downloader = function () use (&$callCount): string {
        $callCount++;
        return '<?php // new';
    };

    $cacheFile = sys_get_temp_dir().'/sphpd_self_update_status_'.md5($scriptFile).'.json';
    file_put_contents($cacheFile, json_encode([
        'checked_at' => time(),
        'has_update' => false,
    ]));

    $updater = new SelfUpdater('http://example.com', '/tmp', $scriptFile, 300, $downloader);
    $status  = $updater->resolveStatus();

    expect($callCount)->toBe(0);       // downloader not called
    expect($status['has_update'])->toBeFalse();

    unlink($scriptFile);
    unlink($cacheFile);
});

test('SelfUpdater: cache is invalidated after update (has_update becomes false)', function () {
    $tmpDir     = sys_get_temp_dir().'/sphpd_upd_'.uniqid();
    mkdir($tmpDir);
    $scriptFile = $tmpDir.'/index.php';
    file_put_contents($scriptFile, '<?php // old');

    $newContent = '<?php // new';
    $downloader = function () use ($newContent): string {
        return $newContent;
    };

    $backupDir = $tmpDir.'/backups';
    $updater   = new SelfUpdater('http://example.com', $backupDir, $scriptFile, 300, $downloader);
    $updater->update();

    $cacheFile = sys_get_temp_dir().'/sphpd_self_update_status_'.md5($scriptFile).'.json';
    $cache     = json_decode(file_get_contents($cacheFile), true);

    expect($cache['has_update'])->toBeFalse();

    // cleanup
    foreach (glob($backupDir.'/*') as $f) unlink($f);
    rmdir($backupDir);
    unlink($scriptFile);
    unlink($cacheFile);
    rmdir($tmpDir);
});

test('SelfUpdater: update writes new content to script file', function () {
    $tmpDir     = sys_get_temp_dir().'/sphpd_upd_'.uniqid();
    mkdir($tmpDir);
    $scriptFile = $tmpDir.'/index.php';
    file_put_contents($scriptFile, '<?php // old');

    $newContent = '<?php // brand new version';
    $downloader = function () use ($newContent): string {
        return $newContent;
    };

    $backupDir = $tmpDir.'/backups';
    $updater   = new SelfUpdater('http://example.com', $backupDir, $scriptFile, 300, $downloader);
    $updater->update();

    expect(file_get_contents($scriptFile))->toBe($newContent);

    // cleanup
    foreach (glob($backupDir.'/*') as $f) unlink($f);
    rmdir($backupDir);
    unlink($scriptFile);
    $cacheFile = sys_get_temp_dir().'/sphpd_self_update_status_'.md5($scriptFile).'.json';
    if (file_exists($cacheFile)) unlink($cacheFile);
    rmdir($tmpDir);
});
