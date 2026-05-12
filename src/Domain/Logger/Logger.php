<?php

declare(strict_types=1);

namespace Sphpd\Domain\Logger;

/**
 * Writes deployment logs and the live-status JSON file.
 *
 * Log files produced per deploy:
 *   *.log      — structured log (tasks + commands + exit codes)
 *   *.html     — human-readable HTML report
 *   *.rlog     — raw append in real time (one line per output chunk)
 *
 * PHP 7.4 compatible.
 */
class Logger
{
    private string $logsPath;
    private string $statusFile;

    public function __construct(string $logsPath, string $statusFile)
    {
        $this->logsPath = rtrim($logsPath, '/');
        $this->statusFile = $statusFile;

        if (!is_dir($this->logsPath)) {
            mkdir($this->logsPath, 0755, true);
        }
    }

    // ── Live-status file ──────────────────────────────────────────────────────

    /**
     * Write the current deployment state to the status file.
     *
     * Minimal contract — callers only set what they know:
     *   running    bool    — is deployment still in progress?
     *   task       string  — current task name
     *   index      int     — current task index (1-based)
     *   total      int     — total tasks
     *   log_file   string  — basename of the .rlog file (for SSE stream)
     *   finished   bool    — true when fully done
     *   success    bool    — final outcome (set alongside finished=true)
     *   duration   float   — total seconds (set alongside finished=true)
     *
     * @param array<string, mixed> $data
     */
    public function updateLiveStatus(array $data): void
    {
        // Merge with existing status so persistent fields like 'pid' survive partial updates
        $existing = [];
        if (file_exists($this->statusFile)) {
            $existing = json_decode((string) file_get_contents($this->statusFile), true) ?? [];
        }
        $merged = array_merge($existing, $data);
        file_put_contents(
            $this->statusFile,
            (string) json_encode($merged, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
        // Ensure www-data (web process) can also write this file even when
        // it was created by a CLI process running as a different user (e.g. root).
        @chmod($this->statusFile, 0666);
    }

    // ── Real-time raw log ─────────────────────────────────────────────────────

    /**
     * Append a raw line to the .rlog file.
     * Called for every output chunk from ProcessRunner so SSE can tail it.
     */
    public function appendRlog(string $rlogPath, string $line): void
    {
        file_put_contents($rlogPath, $line, FILE_APPEND);
    }

    // ── Structured + HTML logs ────────────────────────────────────────────────

    /**
     * Append a line to the structured .log file.
     */
    public function appendLog(string $logPath, string $line): void
    {
        file_put_contents($logPath, $line, FILE_APPEND);
    }

    /**
     * Write the final HTML report file.
     */
    public function writeHtml(string $htmlPath, string $title, string $bodyContent): void
    {
        file_put_contents($htmlPath, $this->buildHtml($title, $bodyContent));
    }

    // ── HTML wrapper ──────────────────────────────────────────────────────────

    public function buildHtml(string $title, string $bodyContent): string
    {
        return <<<HTML
<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{$title}</title>
        <style>
            body  { font-family: Arial, sans-serif; padding: 10px; background: #1a1a2e; color: #e0e0e0; }
            h1    { color: #a78bfa; border-bottom: 1px solid #333; padding-bottom: 8px; }
            h2    { color: #60a5fa; margin-top: 24px; }
            h3    { color: #94a3b8; font-size: .9em; margin: 8px 0 4px; }
            pre   { background: #0f0f1a; padding: 12px; border-radius: 6px; overflow-x: auto; font-size: .85em; }
            code  { font-family: 'Courier New', monospace; }
            .ok   { color: #34d399; }
            .err  { color: #f87171; }
            .meta { color: #64748b; font-size: .8em; }
        </style>
    </head>
    <body>
        {$bodyContent}
    </body>
</html>
HTML;
    }

    // ── Request log ──────────────────────────────────────────────────────────

    /**
     * Append a JSON-encoded request entry to a log file.
     *
     * @param array<string, mixed>  $serverVars
     * @param array<string, mixed>  $queryParams
     * @param array<string, mixed>  $postParams
     * @param array<string, string> $headers
     */
    public function logRequest(
        string $logFilePath,
        string $rawBody,
        array $serverVars = [],
        array $queryParams = [],
        array $postParams = [],
        array $headers = []
    ): void {
        $decodedBody = json_decode($rawBody, true);
        $entry = [
            'timestamp' => date('c'),
            'method' => $serverVars['REQUEST_METHOD'] ?? 'CLI',
            'uri' => $serverVars['REQUEST_URI'] ?? null,
            'remote_addr' => $serverVars['REMOTE_ADDR'] ?? null,
            'query_params' => $queryParams,
            'post_params' => $postParams,
            'json_body' => JSON_ERROR_NONE === json_last_error() ? $decodedBody : null,
            'raw_body' => $rawBody,
            'headers' => $headers,
        ];

        file_put_contents(
            $logFilePath,
            json_encode($entry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
                .PHP_EOL.str_repeat('=', 80).PHP_EOL,
            FILE_APPEND
        );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public function getLogsPath(): string
    {
        return $this->logsPath;
    }

    public function getStatusFile(): string
    {
        return $this->statusFile;
    }
}
