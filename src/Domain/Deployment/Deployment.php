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
 * This class is pure business logic — no HTTP response codes, no exit() calls.
 * The caller (Action layer) is responsible for handling the result and
 * responding to the HTTP client.
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
        $this->config       = $config;
        $this->runner       = $runner;
        $this->instructions = $instructions;
        $this->logger       = $logger;
        $this->notifier     = $notifier;
        $this->statusFile   = $statusFile;
    }

    /**
     * Validate configuration and instructions without running tasks.
     * Returns null on success or an error string.
     */
    public function validate(): ?string
    {
        $projectPath  = $this->config->get('project_path');
        $instructionsFile = $this->config->get('instructions');

        if (empty($projectPath) || !file_exists($projectPath)) {
            return 'Invalid or missing configuration: project_path';
        }

        if (empty($instructionsFile)) {
            return 'Invalid or missing configuration: instructions';
        }

        if (!file_exists($instructionsFile)) {
            return "Instruction file not found: $instructionsFile";
        }

        $result = $this->instructions->load($instructionsFile);
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
        // Concurrent deployment guard
        if (file_exists($this->statusFile)) {
            $current = json_decode(file_get_contents($this->statusFile), true);
            if (!isset($current['finished']) || $current['finished'] !== true) {
                return $this->failure('Deployment already in progress.', 0.0, '');
            }
            unlink($this->statusFile);
        }

        $logFileName  = 'deploy_'.date('Ymd_His').'.log';
        $logsPath     = $this->config->get('logs_path');
        $logFilePath  = $logsPath.'/'.$logFileName;
        $logRawPath   = $logFilePath.'.rlog';
        $logHtmlPath  = $logFilePath.'.html';
        $logFRawPath  = $logFilePath.'.fraw';

        $instructionsFile = $this->config->get('instructions');
        $result = $this->instructions->load($instructionsFile);

        if (isset($result['error'])) {
            $this->notifyFailure($host, 0.0, $logFilePath, '', $result['error']);
            return $this->failure($result['error'], 0.0, $logFileName);
        }

        $tasks = $result['tasks'];
        $startTime = microtime(true);

        $taskResult = $this->runTasks(
            $tasks,
            $logFilePath,
            $logRawPath,
            $logHtmlPath,
            $logFRawPath,
            $this->config->get('project_path'),
            $host,
            $startTime,
            $logFileName
        );

        $duration = round(microtime(true) - $startTime, 2);

        return [
            'success'  => $taskResult['success'],
            'duration' => $duration,
            'logFile'  => $logFileName,
            'error'    => $taskResult['error'],
        ];
    }

    // ── Internal task runner ──────────────────────────────────────────────────

    /**
     * @param array<int, array<string, mixed>> $tasks
     * @return array{success: bool, error: string}
     */
    private function runTasks(
        array $tasks,
        string $logFilePath,
        string $logRawPath,
        string $logHtmlPath,
        string $logFRawPath,
        string $cwd,
        string $host,
        float $startTime,
        string $logFileName
    ): array {
        $totalTasks = count($tasks);
        $taskStatus = [];

        foreach ($tasks as $i => $t) {
            $taskStatus[$i] = ['name' => $t['name'] ?? 'Task '.($i + 1), 'status' => 'pending', 'output' => ''];
        }

        $this->logger->updateLiveStatus([
            'running' => true,
            'task'    => 'Starting...',
            'index'   => 0,
            'total'   => $totalTasks,
            'start'   => $startTime,
            'history' => $taskStatus,
            'current_output' => '',
        ]);

        $fullLog     = 'START: '.date('Y-m-d H:i:s')."\n";
        $fullLogRaw  = 'START: '.date('Y-m-d H:i:s')."\n";
        $htmlContent = '<h1>Deployment Log - Started at '.date('Y-m-d H:i:s')."</h1>\n";
        file_put_contents($logFRawPath, $fullLog);

        $success     = true;
        $failedTask  = '';
        $errorOutput = '';
        $lastIndex   = 0;

        foreach ($tasks as $index => $task) {
            $lastIndex  = $index;
            $taskName   = $task['name'] ?? 'Task #'.($index + 1);
            $commands   = is_array($task['run']) ? $task['run'] : [$task['run']];

            $taskStatus[$index]['status'] = 'running';
            $this->logger->updateLiveStatus([
                'running'        => true,
                'task'           => $taskName,
                'index'          => $index + 1,
                'total'          => $totalTasks,
                'start'          => $startTime,
                'history'        => $taskStatus,
                'current_output' => '',
            ]);

            $fullLog     .= "\n+---------------------------------------------+\n[TASK]: $taskName\n";
            $htmlContent .= "<hr><h2>$taskName</h2>\n";
            file_put_contents($logFRawPath, "\n[TASK]: $taskName\n", FILE_APPEND);

            $taskSuccess = true;

            foreach ($commands as $cmd) {
                $fullLog     .= "[CMD]: $cmd\n";
                $htmlContent .= "<h3>Command: ".htmlspecialchars($cmd)."</h3>\n<pre><code>";
                file_put_contents($logFRawPath, "[CMD]: $cmd\n", FILE_APPEND);

                $result = $this->runner->run($cmd, $cwd);

                $fullLog     .= "STDOUT: {$result['stdout']}\nSTDERR: {$result['stderr']}\nEXIT: {$result['exitCode']}\n";
                $fullLogRaw  .= '['.date('Y-m-d H:i:s')."] $cmd\n";
                $fullLogRaw  .= '['.date('Y-m-d H:i:s')."][info  ] {$result['stdout']}";
                $htmlContent .= htmlspecialchars($result['stdout']);

                if ($result['stderr']) {
                    $htmlContent .= "<span style='color:red;'>".htmlspecialchars($result['stderr'])."</span>";
                    $fullLogRaw  .= '['.date('Y-m-d H:i:s')."][error ] {$result['stderr']}";
                    file_put_contents($logFRawPath, '[STDERR] '.$result['stderr'], FILE_APPEND);
                }

                file_put_contents($logFRawPath, '[STDOUT] '.$result['stdout'], FILE_APPEND);

                $taskStatus[$index]['output'] .= $result['stdout'].$result['stderr'];
                $this->logger->updateLiveStatus([
                    'running'        => true,
                    'task'           => $taskName,
                    'index'          => $index + 1,
                    'total'          => $totalTasks,
                    'start'          => $startTime,
                    'history'        => $taskStatus,
                    'current_output' => $result['stdout'].$result['stderr'],
                ]);

                $hasError = $result['exitCode'] !== 0
                    || preg_match('/(npm error|error:|failed|Error:|ERR!)/i', $result['stderr']);

                if ($hasError) {
                    $taskSuccess = false;
                    $errorOutput = $result['stderr'] ?: $result['stdout'];
                    break;
                }
            }

            $htmlContent .= "</code></pre>\n";

            if ($taskSuccess) {
                $taskStatus[$index]['status'] = 'success';
            } else {
                $success    = false;
                $failedTask = $taskName;
                $taskStatus[$index]['status'] = 'failed';
                $this->logger->updateLiveStatus([
                    'running' => false,
                    'task'    => "FAILED: $taskName",
                    'history' => $taskStatus,
                ]);
                break;
            }
        }

        $duration    = round(microtime(true) - $startTime, 2);
        $htmlContent .= "<h2>Deployment finished in {$duration}s</h2>\n";
        $htmlContent .= '<p><strong>Overall Status: '.($success ? 'SUCCESS' : "FAILED at $failedTask")."</strong></p>\n";

        file_put_contents($logFilePath, $fullLog."\nEND. Duration: {$duration}s");
        file_put_contents($logRawPath, $fullLogRaw."\nEND. Duration: {$duration}s");
        file_put_contents($logFRawPath, "\nEND. Duration: {$duration}s", FILE_APPEND);
        file_put_contents($logHtmlPath, $this->logger->buildHtml("Deployment Log - $failedTask", $htmlContent));

        $this->logger->updateLiveStatus([
            'running'  => false,
            'finished' => true,
            'success'  => $success,
            'task'     => $success ? 'Deployment Finished Successfully' : 'Deployment Failed',
            'index'    => $lastIndex + 1,
            'total'    => $totalTasks,
            'start'    => $startTime,
            'duration' => $duration,
            'history'  => $taskStatus,
            'log_file' => basename($logFilePath),
        ]);

        $protocol = 'http://';
        $logId    = pathinfo($logFilePath, PATHINFO_FILENAME);
        $logUrl   = "$protocol{$host}/log/rview/{$logId}";

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
        string $logFilePath,
        string $failedTask,
        string $error
    ): void {
        $logId  = pathinfo($logFilePath, PATHINFO_FILENAME);
        $logUrl = "http://{$host}/log/rview/{$logId}";
        $this->notifier->send(
            $this->notifier->buildReport($host, false, $duration, $logUrl, $failedTask, $error)
        );
    }
}
