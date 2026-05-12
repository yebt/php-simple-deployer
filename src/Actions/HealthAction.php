<?php

declare(strict_types=1);

namespace Sphpd\Actions;

use Sphpd\Core\Config;
use Sphpd\Core\ViewRenderer;
use Sphpd\Support\SelfUpdater;

/**
 * Health / dashboard views.
 *
 * Handles /health, /health1, /health2, /alllogs, /status/live,
 * validation errors, and the home redirect.
 *
 * PHP 7.4 compatible — no named args, no union types.
 */
class HealthAction
{
    private Config $config;
    private ViewRenderer $view;
    private SelfUpdater $updater;

    /** @var array<string, mixed> */
    private array $defaults;

    /**
     * @param array<string, mixed> $defaults Raw defaults map (key → [value, …])
     */
    public function __construct(
        Config $config,
        ViewRenderer $view,
        SelfUpdater $updater,
        array $defaults
    ) {
        $this->config = $config;
        $this->view = $view;
        $this->updater = $updater;
        $this->defaults = $defaults;
    }

    // -------------------------------------------------------------------------
    // Pages
    // -------------------------------------------------------------------------

    /** GET / — redirect to dashboard */
    public function home(): void
    {
        $route = (string) $this->config->get('dashboard_route');
        header('Location: /'.$route);

        exit;
    }

    /** GET /health */
    public function health(): void
    {
        $cfg = $this->config->all();
        $status = $this->statusData();
        $logs = $this->recentLogs(5);

        $this->view->render('health.latte', [
            'config' => $cfg,
            'defaults' => $this->defaults,
            'isRunning' => $status['isRunning'],
            'instructionExists' => file_exists((string) $cfg['instructions']),
            'artifactInstructionExists' => file_exists((string) $cfg['artifact_instructions']),
            'lastLogs' => $logs,
            'logStatuses' => $this->logStatuses($logs),
            'flash' => $this->flash(),
            'hasUpdate' => $this->hasUpdate(),
            'serverIp' => $_SERVER['SERVER_ADDR'] ?? 'Local',
            'serverDomain' => $_SERVER['HTTP_HOST'] ?? 'Unknown',
            'phpVersion' => PHP_VERSION,
        ]);
    }

    /** GET /health1 */
    public function health1(): void
    {
        $cfg = $this->config->all();
        $status = $this->statusData();
        $logs = $this->recentLogs(3);
        $lastStatus = $logs ? $this->resolveLogStatus($logs[0]) : null;
        $systemReady = file_exists((string) $cfg['instructions']) && !empty($cfg['project_path']);
        $artifactReady = file_exists((string) $cfg['artifact_instructions'])
            && !empty($cfg['gitlab_token'])
            && !empty($cfg['artifact_deploy_dir'])
            && !empty($cfg['gitlab_base_url']);

        $headlineLabel = $this->headlineLabel($status['isRunning'], $lastStatus);
        $headlineClasses = $this->headlineClasses($status['isRunning'], $lastStatus);
        $headlineDot = $this->headlineDotClasses($status['isRunning'], $lastStatus);

        $this->view->render('health1.latte', [
            'config' => $cfg,
            'defaults' => $this->defaults,
            'isRunning' => $status['isRunning'],
            'lastLogStatus' => $lastStatus,
            'instructionExists' => file_exists((string) $cfg['instructions']),
            'artifactInstructionExists' => file_exists((string) $cfg['artifact_instructions']),
            'systemReady' => $systemReady,
            'artifactReady' => $artifactReady,
            'securityEnabled' => !empty($cfg['secure_token']),
            'logsCount' => count($this->logFiles()),
            'lastLogAt' => $logs ? date('Y-m-d H:i:s', (int) filemtime($logs[0])) : 'No runs yet',
            'headlineStatusLabel' => $headlineLabel,
            'headlineStatusClasses' => $headlineClasses,
            'headlineDotClasses' => $headlineDot,
            'lastLogs' => $logs,
            'logStatuses' => $this->logStatuses($logs),
            'logSizeLabels' => $this->logSizeLabels($logs),
            'baseUrl' => $this->baseUrl(),
            'deployWebhookUrl' => $this->baseUrl().'/webhook/deploy',
            'artifactWebhookUrl' => $this->baseUrl().'/webhook/artifact-deploy',
            'flash' => $this->flash(),
            'hasUpdate' => $this->hasUpdate(),
            'hasStatusData' => $status['hasData'],
            'serverIp' => $_SERVER['SERVER_ADDR'] ?? 'Local',
            'serverDomain' => $_SERVER['HTTP_HOST'] ?? 'Unknown',
            'phpVersion' => PHP_VERSION,
            'isProduction' => 'production' === env('APP_ENV'),
        ]);
    }

    /** GET /health2 */
    public function health2(): void
    {
        $cfg = $this->config->all();
        $status = $this->statusData();
        $logs = $this->recentLogs(10);
        $allLogs = $this->logFiles();
        $lastStatus = $allLogs ? $this->resolveLogStatus($allLogs[0]) : null;

        $criticalIssues = [];
        if (empty($cfg['project_path'])) {
            $criticalIssues[] = 'Project path not configured';
        }
        if (!file_exists((string) $cfg['instructions'])) {
            $criticalIssues[] = 'Instructions file missing';
        }

        $this->view->render('health2.latte', [
            'config' => $cfg,
            'systemChecks' => [
                ['label' => 'Project Path', 'ok' => !empty($cfg['project_path'])],
                ['label' => 'Instructions', 'ok' => file_exists((string) $cfg['instructions']), 'detail' => basename((string) $cfg['instructions'])],
                ['label' => 'Logs Path', 'ok' => true],
                ['label' => 'Telegram', 'ok' => !empty($cfg['bot_token'])],
                ['label' => 'Security', 'ok' => !empty($cfg['secure_token'])],
            ],
            'artifactChecks' => [
                ['label' => 'GitLab Token', 'ok' => !empty($cfg['gitlab_token'])],
                ['label' => 'Instructions', 'ok' => file_exists((string) $cfg['artifact_instructions']), 'detail' => basename((string) $cfg['artifact_instructions'])],
                ['label' => 'Deploy Dir', 'ok' => !empty($cfg['artifact_deploy_dir'])],
                ['label' => 'GitLab URL', 'ok' => !empty($cfg['gitlab_base_url'])],
            ],
            'criticalIssues' => $criticalIssues,
            'isRunning' => $status['isRunning'],
            'lastLogStatus' => $lastStatus,
            'artifactInstructionExists' => file_exists((string) $cfg['artifact_instructions']),
            'logsCount' => count($allLogs),
            'lastLogAt' => $allLogs ? date('Y-m-d H:i', (int) filemtime($allLogs[0])) : 'Never',
            'lastLogs' => $logs,
            'logStatuses' => $this->logStatuses($logs),
            'baseUrl' => $this->baseUrl(),
            'flash' => $this->flash(),
            'hasUpdate' => $this->hasUpdate(),
            'hasStatusData' => $status['hasData'],
            'serverDomain' => $_SERVER['HTTP_HOST'] ?? 'Unknown',
            'isProduction' => 'production' === env('APP_ENV'),
        ]);
    }

    /** GET /alllogs */
    public function allLogs(): void
    {
        $validTypes = ['log', 'rlog', 'html', 'fraw'];
        $type = $_GET['type'] ?? 'log';
        if (!in_array($type, $validTypes, true)) {
            $type = 'log';
        }

        $globMap = ['log' => '*.log', 'rlog' => '*.rlog', 'html' => '*.html', 'fraw' => '*.fraw'];
        $logsPath = (string) $this->config->get('logs_path');
        $files = glob($logsPath.'/'.$globMap[$type]) ?: [];
        usort($files, static function (string $a, string $b): int {
            return (int) filemtime($b) - (int) filemtime($a);
        });

        // For log type, resolve statuses; for others, use the paired .log file
        $statuses = [];
        $sizes = [];
        foreach ($files as $f) {
            $fn = basename($f);
            $id = (string) preg_replace('/\.log.*$/', '', $fn);
            $baseLog = $logsPath.'/'.$id.'.log';
            $statuses[$f] = $this->resolveLogStatus($baseLog);
            $sizes[$f] = $this->formatBytes((int) filesize($f));
        }

        $cfg = $this->config->get('dashboard_route');

        $this->view->render('logs.latte', [
            'type' => $type,
            'logs' => $files,
            'logStatuses' => $statuses,
            'logSizes' => $sizes,
            'dashboardUrl' => '/'.(string) $cfg,
        ]);
    }

    /** GET /status/live */
    public function liveStatus(): void
    {
        $logsPath = (string) $this->config->get('logs_path');
        $statusFile = $logsPath.'/.current_status';

        if (!file_exists($statusFile)) {
            header('Location: /'.(string) $this->config->get('dashboard_route'));

            exit;
        }

        $raw = (string) file_get_contents($statusFile);
        $data = json_decode($raw, true) ?? [];

        $dashboardRoute = (string) ($this->config->get('dashboard_route') ?? 'health');
        $protocol = (!empty($_SERVER['HTTPS']) && 'off' !== $_SERVER['HTTPS']) ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $dashboardUrl = $protocol.'://'.$host.'/'.$dashboardRoute;

        $this->view->render('live-status.latte', [
            'dashboardUrl' => $dashboardUrl,
            'currentTask' => $data['task'] ?? 'Initializing…',
            'index' => (int) ($data['index'] ?? 0),
            'total' => (int) ($data['total'] ?? 0),
            'logFile' => $data['log_file'] ?? '',
        ]);
    }

    /** Render validation error page */
    public function notFound(): void
    {
        $dashboardUrl = '/'.(string) $this->config->get('dashboard_route');
        $this->view->render('404.latte', [
            'dashboardUrl' => $dashboardUrl,
        ]);
    }

    public function validationError(string $message): void
    {
        $dashboardUrl = '/'.(string) $this->config->get('dashboard_route');
        $this->view->render('validation-error.latte', [
            'errorMessage' => $message,
            'dashboardUrl' => $dashboardUrl,
        ]);
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /** @return array{isRunning: bool, hasData: bool} */
    private function statusData(): array
    {
        $file = (string) $this->config->get('logs_path').'/.current_status';
        if (!file_exists($file)) {
            return ['isRunning' => false, 'hasData' => false];
        }
        $data = json_decode((string) file_get_contents($file), true);
        $running = $data && (!isset($data['finished']) || !$data['finished']);

        return ['isRunning' => (bool) $running, 'hasData' => true];
    }

    /** @return string[] */
    private function logFiles(): array
    {
        $logs = glob((string) $this->config->get('logs_path').'/*.log') ?: [];
        usort($logs, static function (string $a, string $b): int {
            return (int) filemtime($b) - (int) filemtime($a);
        });

        return $logs;
    }

    /** @return string[] */
    private function recentLogs(int $count): array
    {
        return array_slice($this->logFiles(), 0, $count);
    }

    /** @param string[] $paths */
    private function logStatuses(array $paths): array
    {
        $out = [];
        foreach ($paths as $p) {
            $out[$p] = $this->resolveLogStatus($p);
        }

        return $out;
    }

    /** @param string[] $paths */
    private function logSizeLabels(array $paths): array
    {
        $out = [];
        foreach ($paths as $p) {
            $out[$p] = $this->formatBytes((int) filesize($p));
        }

        return $out;
    }

    private function resolveLogStatus(string $path): ?bool
    {
        if (!file_exists($path)) {
            return null;
        }
        $content = (string) file_get_contents($path);
        if ('' === trim($content)) {
            return null;
        }
        $lines = (array) preg_split('/\r\n|\r|\n/', trim($content));
        $exitLine = $lines[count($lines) - 5] ?? end($lines) ?? '';
        if ('' === $exitLine || false === $exitLine) {
            return null;
        }

        return false !== strpos($exitLine, 'EXIT: 0');
    }

    private function formatBytes(int $size): string
    {
        if ($size < 1024) {
            return $size.' B';
        }
        if ($size < 1048576) {
            return round($size / 1024, 1).' KB';
        }

        return round($size / 1048576, 2).' MB';
    }

    private function baseUrl(): string
    {
        $host = $_SERVER['HTTP_HOST'] ?? (defined('CLI_HOST') ? CLI_HOST : 'localhost');
        $https = (!empty($_SERVER['HTTPS']) && 'off' !== $_SERVER['HTTPS'])
            || (isset($_SERVER['SERVER_PORT']) && '443' === (string) $_SERVER['SERVER_PORT']);

        return ($https ? 'https://' : 'http://').$host;
    }

    /** @return null|array{type:string,message:string} */
    private function flash(): ?array
    {
        if (isset($_GET['updated'])) {
            if ('1' === $_GET['updated']) {
                $backup = $_GET['backup'] ?? 'backups/index.php.bak';

                return ['type' => 'success', 'message' => 'Script updated successfully. Backup: '.$backup];
            }

            return ['type' => 'error', 'message' => $_GET['message'] ?? 'Unable to update the script.'];
        }
        if (($_GET['cleared'] ?? null) === '1') {
            return ['type' => 'success', 'message' => 'History cleared successfully.'];
        }
        if (isset($_GET['notified'])) {
            $ok = '1' === $_GET['notified'];

            return [
                'type' => $ok ? 'success' : 'error',
                'message' => $ok ? 'Test notification sent successfully.' : 'Unable to send the test notification.',
            ];
        }

        return null;
    }

    private function hasUpdate(): bool
    {
        try {
            $status = $this->updater->checkForUpdate();

            return $status['has_update'] ?? false;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function headlineLabel(bool $isRunning, ?bool $lastStatus): string
    {
        if ($isRunning) {
            return 'Deployment running';
        }
        if (true === $lastStatus) {
            return 'System healthy';
        }
        if (false === $lastStatus) {
            return 'Needs attention';
        }

        return 'Ready for deploy';
    }

    private function headlineClasses(bool $isRunning, ?bool $lastStatus): string
    {
        if ($isRunning) {
            return 'border-blue-500/30 bg-blue-500/10 text-blue-600 dark:text-blue-300';
        }
        if (true === $lastStatus) {
            return 'border-emerald-500/30 bg-emerald-500/10 text-emerald-600 dark:text-emerald-300';
        }
        if (false === $lastStatus) {
            return 'border-amber-500/30 bg-amber-500/10 text-amber-600 dark:text-amber-300';
        }

        return 'border-slate-300 bg-white text-slate-600 dark:border-slate-700 dark:bg-slate-900/40 dark:text-slate-300';
    }

    private function headlineDotClasses(bool $isRunning, ?bool $lastStatus): string
    {
        if ($isRunning) {
            return 'bg-blue-500 animate-pulse';
        }
        if (true === $lastStatus) {
            return 'bg-emerald-500';
        }
        if (false === $lastStatus) {
            return 'bg-amber-500';
        }

        return 'bg-slate-400 dark:bg-slate-500';
    }
}
