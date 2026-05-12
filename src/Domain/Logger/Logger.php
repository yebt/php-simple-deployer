<?php

declare(strict_types=1);

namespace Sphpd\Domain\Logger;

/**
 * Writes deployment logs and the live-status JSON file.
 *
 * PHP 7.4 compatible.
 */
class Logger
{
    private string $logsPath;
    private string $statusFile;

    public function __construct(string $logsPath, string $statusFile)
    {
        $this->logsPath   = rtrim($logsPath, '/');
        $this->statusFile = $statusFile;

        if (!is_dir($this->logsPath)) {
            mkdir($this->logsPath, 0755, true);
        }
    }

    // ── Log HTML wrapper ──────────────────────────────────────────────────────

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
            body { font-family: Arial, sans-serif; padding: 10px; background-color: #f9f9f9; }
            h1, h2, h3 { color: #333; }
            pre { background-color: #f0f0f0; padding: 10px; border-radius: 5px; overflow-x: auto; }
            code { font-family: 'Courier New', Courier, monospace; }
        </style>
    </head>
    <body>
        {$bodyContent}
    </body>
</html>
HTML;
    }

    // ── Live-status file ──────────────────────────────────────────────────────

    /** @param array<string, mixed> $data */
    public function updateLiveStatus(array $data): void
    {
        file_put_contents($this->statusFile, json_encode($data));
    }

    // ── Request log ──────────────────────────────────────────────────────────

    /**
     * Append a JSON-encoded request entry to a log file.
     *
     * @param array<string, mixed> $serverVars   $_SERVER equivalent
     * @param array<string, mixed> $queryParams  $_GET equivalent
     * @param array<string, mixed> $postParams   $_POST equivalent
     * @param array<string, string> $headers     normalised HTTP headers
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
            'timestamp'   => date('c'),
            'method'      => $serverVars['REQUEST_METHOD'] ?? 'CLI',
            'uri'         => $serverVars['REQUEST_URI'] ?? null,
            'remote_addr' => $serverVars['REMOTE_ADDR'] ?? null,
            'query_params' => $queryParams,
            'post_params'  => $postParams,
            'json_body'    => json_last_error() === JSON_ERROR_NONE ? $decodedBody : null,
            'raw_body'     => $rawBody,
            'headers'      => $headers,
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
