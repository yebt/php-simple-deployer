<?php

declare(strict_types=1);

namespace Sphpd\Support;

/**
 * Handles self-update checks and script replacement.
 *
 * Both the HTTP downloader and the script path are injected so the class
 * is fully testable without network access or touching real files.
 *
 * PHP 7.4 compatible.
 */
class SelfUpdater
{
    private string $updateUrl;
    private string $backupDir;
    private string $scriptPath;
    private int $cacheTtl;

    /** @var callable(string): string */
    private $downloader;

    /**
     * @param null|callable $downloader Override for testing.
     *                                  Signature: (string $url): string — returns remote content.
     */
    public function __construct(
        string $updateUrl,
        string $backupDir,
        string $scriptPath,
        int $cacheTtl = 300,
        ?callable $downloader = null
    ) {
        $this->updateUrl = $updateUrl;
        $this->backupDir = rtrim($backupDir, '/');
        $this->scriptPath = $scriptPath;
        $this->cacheTtl = $cacheTtl;
        $this->downloader = $downloader ?? static function (string $url): string {
            $content = file_get_contents($url);
            if (false === $content) {
                throw new \RuntimeException("Failed to download: {$url}");
            }

            return $content;
        };
    }

    // ── Update check ──────────────────────────────────────────────────────────

    /**
     * @return array{checked_at: int, has_update: bool}
     */
    public function resolveStatus(): array
    {
        $cacheFile = $this->cacheFile();

        $cached = $this->readCache($cacheFile);
        if ($this->isFresh($cached)) {
            return $cached;
        }

        try {
            $remoteContent = ($this->downloader)($this->updateUrl);
            $currentHash = hash_file('sha256', $this->scriptPath);
            $remoteHash = hash('sha256', $remoteContent);

            $status = [
                'checked_at' => time(),
                'has_update' => $currentHash !== $remoteHash,
            ];

            file_put_contents($cacheFile, json_encode($status), LOCK_EX);

            return $status;
        } catch (\Throwable $e) {
            error_log('Self-update check failed: '.$e->getMessage());

            return $cached ?? ['checked_at' => time(), 'has_update' => false];
        }
    }

    // ── Script replacement ────────────────────────────────────────────────────

    /**
     * Download the remote script and replace the current file atomically.
     *
     * @return array{backup_path: string, bytes: int}
     *
     * @throws \RuntimeException on any failure
     */
    public function update(): array
    {
        $remoteContent = ($this->downloader)($this->updateUrl);
        $expectedBytes = strlen($remoteContent);
        $timestamp = date('Ymd_His');
        $backupPath = $this->backupDir.'/index.php.bak.'.$timestamp;
        $permissions = fileperms($this->scriptPath);
        $tmpFile = tempnam(sys_get_temp_dir(), 'sphpd_update_');

        if (false === $tmpFile) {
            throw new \RuntimeException('Unable to create a temporary file for the update.');
        }

        if (!is_dir($this->backupDir) && !mkdir($this->backupDir, 0777, true)) {
            unlink($tmpFile);

            throw new \RuntimeException('Unable to create backup directory at '.$this->backupDir.'.');
        }

        $writtenBytes = file_put_contents($tmpFile, $remoteContent, LOCK_EX);

        if (false === $writtenBytes || $writtenBytes !== $expectedBytes) {
            unlink($tmpFile);

            throw new \RuntimeException('Unable to write downloaded script to the temporary file.');
        }

        if (false !== $permissions) {
            chmod($tmpFile, $permissions & 0777);
        }

        if (!rename($this->scriptPath, $backupPath)) {
            unlink($tmpFile);

            throw new \RuntimeException('Unable to move current script to '.$backupPath.'.');
        }

        if (!rename($tmpFile, $this->scriptPath)) {
            rename($backupPath, $this->scriptPath);
            unlink($tmpFile);

            throw new \RuntimeException('Unable to replace script with the downloaded version.');
        }

        clearstatcache(true, $this->scriptPath);

        // Invalidate cache so the update banner disappears immediately
        file_put_contents(
            $this->cacheFile(),
            json_encode(['checked_at' => time(), 'has_update' => false]),
            LOCK_EX
        );

        return ['backup_path' => $backupPath, 'bytes' => $writtenBytes];
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function cacheFile(): string
    {
        return sys_get_temp_dir().'/sphpd_self_update_status_'.md5($this->scriptPath).'.json';
    }

    /** @param null|array<string, mixed> $cached */
    private function isFresh(?array $cached): bool
    {
        if (null === $cached || !array_key_exists('checked_at', $cached)) {
            return false;
        }

        return (time() - (int) $cached['checked_at']) < $this->cacheTtl;
    }

    /**
     * @return null|array{checked_at: int, has_update: bool}
     */
    private function readCache(string $cacheFile): ?array
    {
        if (!is_file($cacheFile)) {
            return null;
        }

        $decoded = json_decode(file_get_contents($cacheFile), true);

        return is_array($decoded) ? $decoded : null;
    }
}
