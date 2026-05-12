<?php

declare(strict_types=1);

namespace Sphpd\Actions;

use Sphpd\Core\Config;

/**
 * Log viewer endpoints: /log/*, /alllogs, /clear-history.
 */
class LogAction
{
    private Config $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /** GET /log/view?file=<name> */
    public function view(): void
    {
        $this->serveFile($this->logsPath().'/'.basename($_GET['file'] ?? ''));
    }

    /** GET /log/rview/<id> — raw .log */
    public function rawView(string $id): void
    {
        $this->serveFile($this->logsPath().'/'.$id.'.log');
    }

    /** GET /log/bview/<id> — base raw .log.rlog */
    public function baseRawView(string $id): void
    {
        $this->serveFile($this->logsPath().'/'.$id.'.log.rlog');
    }

    /** GET /log/htmlview/<id> — HTML log */
    public function htmlView(string $id): void
    {
        $this->serveFile($this->logsPath().'/'.$id.'.log.html', 'text/html');
    }

    /** GET /log/frawview/<id> — fraw log */
    public function frawView(string $id): void
    {
        $this->serveFile($this->logsPath().'/'.$id.'.log.fraw');
    }

    /** GET /log/last — latest .log */
    public function last(): void
    {
        $file = $this->latestGlob('*.log');
        if (null === $file) {
            exit('No logs available.');
        }
        header('Content-Type: text/plain');
        readfile($file);
    }

    /** GET /log/lasthtml — latest .html log */
    public function lastHtml(): void
    {
        $file = $this->latestGlob('*.html');
        if (null === $file) {
            exit('No HTML logs available.');
        }
        header('Content-Type: text/html');
        readfile($file);
    }

    /** GET /log/lastfraw — latest .fraw log */
    public function lastFraw(): void
    {
        $file = $this->latestGlob('*.fraw');
        if (null === $file) {
            exit('No fraw logs available.');
        }
        header('Content-Type: text/plain');
        readfile($file);
    }

    /** GET /clear-history */
    public function clearHistory(): void
    {
        $statusFile = $this->logsPath().'/.current_status';

        $files = glob($this->logsPath().'/*') ?: [];
        foreach ($files as $f) {
            unlink($f);
        }

        if (file_exists($statusFile)) {
            $current = json_decode((string) file_get_contents($statusFile), true);
            if (isset($current['finished']) && true === $current['finished']) {
                unlink($statusFile);
            } else {
                http_response_code(409);

                exit('Deployment already in progress.');
            }
        }

        header('Location: '.$this->dashboardUrl('cleared=1'));
    }

    private function logsPath(): string
    {
        return (string) $this->config->get('logs_path');
    }

    private function dashboardUrl(string $query = ''): string
    {
        $route = (string) $this->config->get('dashboard_route');

        return '/'.$route.($query ? '?'.$query : '');
    }

    // -------------------------------------------------------------------------

    private function serveFile(string $path, string $contentType = 'text/plain'): void
    {
        if (!file_exists($path)) {
            http_response_code(404);

            exit('File not found.');
        }
        header('Content-Type: '.$contentType);
        readfile($path);
    }

    /**
     * Returns the most recently modified file matching $glob inside logs_path,
     * or null if none exist.
     */
    private function latestGlob(string $glob): ?string
    {
        $files = glob($this->logsPath().'/'.$glob);
        if (!$files) {
            return null;
        }
        usort($files, static function (string $a, string $b): int {
            return filemtime($b) - filemtime($a);
        });

        return $files[0];
    }
}
