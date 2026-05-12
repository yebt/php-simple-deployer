<?php

declare(strict_types=1);

namespace Sphpd\Domain\Deployment;

use Sphpd\Core\Config;
use Sphpd\Domain\Instructions\Instructions;
use Sphpd\Domain\Logger\Logger;
use Sphpd\Domain\Notifier\TelegramNotifier;

/**
 * Orchestrates a standard git-based deployment.
 *
 * Produces three log files per run:
 *   *.log   — structured log (tasks, commands, exit codes)
 *   *.html  — human-readable HTML report
 *   *.rlog  — raw real-time append (streamed chunk by chunk via SSE)
 *
 * PHP 7.4 compatible.
 */
class Deployment
{
    private Config $config;
    private ProcessRunner $runner;
    private Instructions $instructions;
    private Logger $logger;
    private TelegramNotifier $notifier;
    private string $statusFile;

    public function __construct(
        Config $config,
        ProcessRunner $runner,
        Instructions $instructions,
        Logger $logger,
        TelegramNotifier $notifier,
        string $statusFile
    ) {
        $this->config = $config;
        $this->runner = $runner;
        $this->instructions = $instructions;
        $this->logger = $logger;
        $this->notifier = $notifier;
        $this->statusFile = $statusFile;
    }

    /**
     * Validate configuration and instructions without running tasks.
     * Returns null on success or an error string.
     */
    public function validate(): ?string
    {
        $projectPath = $this->config->get('project_path');
        $instructionsFile = $this->config->get('instructions');

        $resolvedPath = realpath((string) $projectPath);
        if (empty($projectPath) || false === $resolvedPath || !is_dir($resolvedPath)) {
            return "Invalid or missing configuration: project_path \"{$projectPath}\" does not exist";
        }

        if (empty($instructionsFile)) {
            return 'Invalid or missing configuration: instructions';
        }

        if (!file_exists((string) $instructionsFile)) {
            return "Instruction file not found: {$instructionsFile}";
        }

        $result = $this->instructions->load((string) $instructionsFile);
        if (isset($result['error'])) {
            return $result['error'];
        }

        return null;
    }

    /**
     * Run the full deployment.
     *
     * @return array{success: bool, duration: float, logFile: string, error: string}
     */
    public function run(string $host = 'localhost'): array
    {
        // Concurrent deployment guard — block only if actively running
        if (file_exists($this->statusFile)) {
            $current = json_decode((string) file_get_contents($this->statusFile), true);
            if (isset($current['running']) && true === $current['running']) {
                return $this->failure('Deployment already in progress.', 0.0, '');
            }
            unlink($this->statusFile);
        }

        $baseName = 'deploy_' . date('Ymd_His');
        $logsPath = rtrim((string) $this->config->get('logs_path'), '/');
        $logPath = $logsPath . '/' . $baseName . '.log';
        $rlogPath = $logsPath . '/' . $baseName . '.rlog';
        $htmlPath = $logsPath . '/' . $baseName . '.html';

        $instructionsFile = (string) $this->config->get('instructions');
        $result = $this->instructions->load($instructionsFile);

        if (isset($result['error'])) {
            $this->notifyFailure($host, 0.0, $logPath, '', $result['error']);

            return $this->failure($result['error'], 0.0, $baseName . '.log');
        }

        $projectPath = (string) $this->config->get('project_path');
        $resolvedCwd = realpath($projectPath);
        if (!$resolvedCwd && defined('ROOT_PATH')) {
            $rootBaseCall = constant('ROOT_PATH') .'/'. $projectPath;
            $resolvedCwd = realpath($rootBaseCall);
        }
        if (false === $resolvedCwd || !is_dir($resolvedCwd)) {
            $msg = "project_path \"{$projectPath}\" does not exist or is not a directory";
            $this->notifyFailure($host, 0.0, $logPath, '', $msg);

            return $this->failure($msg, 0.0, $baseName . '.log');
        }

        $tasks = $result['tasks'];
        $startTime = microtime(true);


        $taskResult = $this->runTasks(
            $tasks,
            $logPath,
            $rlogPath,
            $htmlPath,
            $resolvedCwd,
            $host,
            $startTime,
            $baseName
        );

        $duration = round(microtime(true) - $startTime, 2);

        return [
            'success' => $taskResult['success'],
            'duration' => $duration,
            'logFile' => $baseName . '.log',
            'error' => $taskResult['error'],
        ];
    }

    // ── Internal task runner ──────────────────────────────────────────────────

    /**
     * @param array<int, array<string, mixed>> $tasks
     *
     * @return array{success: bool, error: string}
     */
    private function runTasks(
        array $tasks,
        string $logPath,
        string $rlogPath,
        string $htmlPath,
        string $cwd,
        string $host,
        float $startTime,
        string $baseName
    ): array {
        $totalTasks = count($tasks);

        // Initialise status — broadcast rlog filename so SSE knows which file to tail
        $this->logger->updateLiveStatus([
            'running' => true,
            'pid' => getmypid(),
            'task' => 'Starting…',
            'index' => 0,
            'total' => $totalTasks,
            'log_file' => $baseName . '.rlog',
        ]);

        // Seed the rlog and structured log
        $header = '[' . date('Y-m-d H:i:s') . "] === DEPLOYMENT START ===\n";
        $this->logger->appendRlog($rlogPath, $header);
        $this->logger->appendLog($logPath, $header);

        $htmlBody = '<h1>Deployment — ' . date('Y-m-d H:i:s') . "</h1>\n";
        $success = true;
        $failedTask = '';
        $errorOutput = '';
        $lastIndex = 0;

        foreach ($tasks as $index => $task) {
            $lastIndex = $index;
            $taskName = $task['name'] ?? 'Task #' . ($index + 1);
            $commands = is_array($task['run']) ? $task['run'] : [$task['run']];
            $taskSuccess = true;

            // ── Task start ────────────────────────────────────────────────────
            $taskHeader = "\n[" . date('Y-m-d H:i:s') . '] +-- TASK ' . ($index + 1) . "/{$totalTasks}: {$taskName}\n";
            $this->logger->appendRlog($rlogPath, $taskHeader);
            $this->logger->appendLog($logPath, $taskHeader);
            $htmlBody .= '<hr><h2>' . htmlspecialchars($taskName) . "</h2>\n";

            $this->logger->updateLiveStatus([
                'running' => true,
                'task' => $taskName,
                'index' => $index + 1,
                'total' => $totalTasks,
                'log_file' => $baseName . '.rlog',
            ]);

            // ── Commands ──────────────────────────────────────────────────────
            foreach ($commands as $cmd) {
                $cmdHeader = '[' . date('Y-m-d H:i:s') . "] $ {$cmd}\n";
                $this->logger->appendRlog($rlogPath, $cmdHeader);
                $this->logger->appendLog($logPath, "[CMD] {$cmd}\n");
                $htmlBody .= '<h3>$ ' . htmlspecialchars((string) $cmd) . "</h3>\n<pre><code>";

                $cmdStdout = '';
                $cmdStderr = '';

                // Real-time streaming callback — writes each chunk to .rlog immediately
                $result = $this->runner->run(
                    (string) $cmd,
                    $cwd,
                    function (string $type, string $chunk) use ($rlogPath, &$cmdStdout, &$cmdStderr): void {
                        if ('out' === $type) {
                            $cmdStdout .= $chunk;
                        } else {
                            $cmdStderr .= $chunk;
                        }
                        $this->logger->appendRlog($rlogPath, $chunk);
                    }
                );

                // Structured log — written after command completes
                $this->logger->appendLog(
                    $logPath,
                    "STDOUT: {$result['stdout']}STDERR: {$result['stderr']}EXIT: {$result['exitCode']}\n"
                );

                $htmlBody .= htmlspecialchars($result['stdout']);
                if ($result['stderr']) {
                    $htmlBody .= "<span class='err'>" . htmlspecialchars($result['stderr']) . '</span>';
                }
                $htmlBody .= "</code></pre>\n";

                $hasError = 0 !== $result['exitCode']
                    || (bool) preg_match('/(npm error|error:|failed|Error:|ERR!)/i', $result['stderr']);

                if ($hasError) {
                    $taskSuccess = false;
                    $errorOutput = $result['stderr'] ?: $result['stdout'];

                    break;
                }
            }

            // ── Task result ───────────────────────────────────────────────────
            if ($taskSuccess) {
                $line = '[' . date('Y-m-d H:i:s') . "] ✓ TASK DONE: {$taskName}\n";
                $this->logger->appendRlog($rlogPath, $line);
                $this->logger->appendLog($logPath, $line);
            } else {
                $success = false;
                $failedTask = $taskName;
                $line = '[' . date('Y-m-d H:i:s') . "] ✗ TASK FAILED: {$taskName}\n";
                $this->logger->appendRlog($rlogPath, $line);
                $this->logger->appendLog($logPath, $line);

                break;
            }
        }

        // ── Finalize ──────────────────────────────────────────────────────────
        $duration = round(microtime(true) - $startTime, 2);
        $status = $success ? 'SUCCESS' : "FAILED at: {$failedTask}";
        $footer = '[' . date('Y-m-d H:i:s') . "] === DEPLOYMENT {$status} ({$duration}s) ===\n";

        $this->logger->appendRlog($rlogPath, $footer);
        $this->logger->appendLog($logPath, $footer);

        $htmlBody .= "<hr><h2 class='" . ($success ? 'ok' : 'err') . "'>{$status} — {$duration}s</h2>\n";
        $this->logger->writeHtml($htmlPath, "Deploy — {$status}", $htmlBody);

        $this->logger->updateLiveStatus([
            'running' => false,
            'finished' => true,
            'success' => $success,
            'task' => $success ? 'Deployment finished successfully' : "Failed: {$failedTask}",
            'index' => $lastIndex + 1,
            'total' => $totalTasks,
            'duration' => $duration,
            'log_file' => $baseName . '.rlog',
        ]);

        // ── Telegram notification ─────────────────────────────────────────────
        $logId = $baseName;
        $logUrl = 'http://' . $host . '/log/rview/' . $logId;
        $this->notifier->send(
            $this->notifier->buildReport($host, $success, $duration, $logUrl, $failedTask, $errorOutput)
        );

        return ['success' => $success, 'error' => $errorOutput];
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** @return array{success: bool, duration: float, logFile: string, error: string} */
    private function failure(string $error, float $duration, string $logFile): array
    {
        return ['success' => false, 'duration' => $duration, 'logFile' => $logFile, 'error' => $error];
    }

    private function notifyFailure(
        string $host,
        float $duration,
        string $logPath,
        string $failedTask,
        string $error
    ): void {
        $logId = pathinfo($logPath, PATHINFO_FILENAME);
        $logUrl = 'http://' . $host . '/log/rview/' . $logId;
        $this->notifier->send(
            $this->notifier->buildReport($host, false, $duration, $logUrl, $failedTask, $error)
        );
    }
}
