<?php

declare(strict_types=1);

namespace Sphpd\Domain\Deployment;

use Sphpd\Core\Config;
use Sphpd\Domain\Instructions\Instructions;
use Sphpd\Domain\Logger\Logger;
use Sphpd\Domain\Notifier\TelegramNotifier;

/**
 * Orchestrates an artifact-based deployment (GitLab CI zip → extract → run tasks).
 *
 * Produces three log files per run:
 *   *.log   — structured log (tasks, commands, exit codes)
 *   *.html  — human-readable HTML report
 *   *.rlog  — raw real-time append (streamed chunk by chunk via SSE)
 *
 * No HTTP response codes, no exit() — those belong to the Action layer.
 * PHP 7.4 compatible.
 */
class ArtifactDeployment
{
    private Config $config;
    private ProcessRunner $runner;
    private Instructions $instructions;
    private Logger $logger;
    private TelegramNotifier $notifier;
    private string $statusFile;

    /**
     * HTTP downloader for the artifact file.
     * Signature: (string $url, string $destFile, string $token): array{code:int, error:string}
     *
     * @var callable
     */
    private $downloader;

    public function __construct(
        Config $config,
        ProcessRunner $runner,
        Instructions $instructions,
        Logger $logger,
        TelegramNotifier $notifier,
        string $statusFile,
        ?callable $downloader = null
    ) {
        $this->config       = $config;
        $this->runner       = $runner;
        $this->instructions = $instructions;
        $this->logger       = $logger;
        $this->notifier     = $notifier;
        $this->statusFile   = $statusFile;
        $this->downloader   = $downloader ?? [$this, 'curlDownload'];
    }

    /**
     * Run the artifact deployment.
     *
     * @return array{success: bool, error: string, logFile: string}
     */
    public function run(
        string $projectId,
        string $job,
        string $jobId,
        string $branch = 'main',
        string $host = 'localhost'
    ): array {
        if (empty($projectId)) {
            return $this->failure('Missing params: projectId is required', '');
        }

        if (empty($jobId)) {
            return $this->failure('Missing params: jobId is required', '');
        }

        // Concurrent deployment guard — block only if actively running
        if (file_exists($this->statusFile)) {
            $current = json_decode((string) file_get_contents($this->statusFile), true);
            if (isset($current['running']) && $current['running'] === true) {
                return $this->failure('Deployment already in progress.', '');
            }
            unlink($this->statusFile);
        }

        $baseName    = 'artifact_deploy_' . date('Ymd_His');
        $logsPath    = rtrim((string) $this->config->get('logs_path'), '/');
        $logPath     = $logsPath . '/' . $baseName . '.log';
        $rlogPath    = $logsPath . '/' . $baseName . '.rlog';
        $htmlPath    = $logsPath . '/' . $baseName . '.html';
        $startTime   = microtime(true);

        // Lock status immediately — set rlog filename so SSE can start tailing
        $this->logger->updateLiveStatus([
            'running'  => true,
            'task'     => 'Downloading artifact…',
            'index'    => 0,
            'total'    => 0,
            'log_file' => $baseName . '.rlog',
        ]);

        // Seed the rlog
        $header = '[' . date('Y-m-d H:i:s') . "] === ARTIFACT DEPLOY START ===\n";
        $this->logger->appendRlog($rlogPath, $header);
        $this->logger->appendLog($logPath, $header);

        $htmlBody = '<h1>Artifact Deploy — ' . date('Y-m-d H:i:s') . "</h1>\n";

        // Helper to log a pre-task line to both files
        $logLine = function (string $line, string $level = 'info') use ($logPath, $rlogPath): void {
            $ts   = date('Y-m-d H:i:s');
            $text = "[$ts][$level] $line\n";
            $this->logger->appendRlog($rlogPath, $text);
            $this->logger->appendLog($logPath, $text);
        };

        // ── Validate config ───────────────────────────────────────────────────
        $gitlabToken = (string) $this->config->get('gitlab_token');
        if (empty($gitlabToken)) {
            $logLine('GITLAB_TOKEN not configured', 'error');
            return $this->writeFailure('GITLAB_TOKEN not configured', $logPath, $rlogPath, $htmlPath, $htmlBody, $baseName, $startTime, $host, 'Config');
        }

        $instructionsFile = (string) $this->config->get('artifact_instructions');
        if (!file_exists($instructionsFile)) {
            $msg = "Artifact instructions file not found: $instructionsFile";
            $logLine($msg, 'error');
            return $this->writeFailure($msg, $logPath, $rlogPath, $htmlPath, $htmlBody, $baseName, $startTime, $host, 'Config');
        }

        // ── Prepare deploy directory ──────────────────────────────────────────
        $deployDir = (string) $this->config->get('artifact_deploy_dir');
        if (!is_dir($deployDir)) {
            mkdir($deployDir, 0755, true);
        }

        // ── Download artifact ─────────────────────────────────────────────────
        $artifactFile = $deployDir . '/artifact.zip';
        $gitlabBase   = rtrim((string) $this->config->get('gitlab_base_url'), '/');
        $artifactUrl  = "{$gitlabBase}/api/v4/projects/{$projectId}/jobs/{$jobId}/artifacts";

        $logLine("Downloading artifact — project: $projectId | branch: $branch | job: $job");
        $logLine("URL: $artifactUrl");

        $dl = ($this->downloader)($artifactUrl, $artifactFile, $gitlabToken);

        if ($dl['code'] !== 200 || $dl['error'] !== '') {
            $msg = "Artifact download failed (HTTP {$dl['code']}): {$dl['error']}";
            $logLine($msg, 'error');
            return $this->writeFailure($msg, $logPath, $rlogPath, $htmlPath, $htmlBody, $baseName, $startTime, $host, 'Download');
        }

        $fileSize = round(filesize($artifactFile) / 1024, 1);
        $logLine("Artifact downloaded successfully — {$fileSize} KB");

        // ── Extract ───────────────────────────────────────────────────────────
        $this->logger->updateLiveStatus([
            'running'  => true,
            'task'     => 'Extracting artifact…',
            'log_file' => $baseName . '.rlog',
        ]);

        $extractDir = $deployDir . '/extracted';
        if (is_dir($extractDir)) {
            exec('rm -rf ' . escapeshellarg($extractDir));
        }
        mkdir($extractDir, 0755, true);

        $logLine("Extracting artifact to: $extractDir");

        if (!$this->extract($artifactFile, $extractDir)) {
            $logLine('Failed to extract artifact', 'error');
            return $this->writeFailure('Failed to extract artifact', $logPath, $rlogPath, $htmlPath, $htmlBody, $baseName, $startTime, $host, 'Extract');
        }

        $logLine('Artifact extracted successfully');

        // ── Load instructions ─────────────────────────────────────────────────
        $result = $this->instructions->load($instructionsFile);
        if (isset($result['error'])) {
            $logLine($result['error'], 'error');
            return $this->writeFailure($result['error'], $logPath, $rlogPath, $htmlPath, $htmlBody, $baseName, $startTime, $host);
        }

        $tasks = $result['tasks'];
        $logLine('Instructions validated — ' . count($tasks) . ' task(s) queued');
        $logLine("Starting task execution in: $extractDir");

        // ── Run tasks ─────────────────────────────────────────────────────────
        $taskResult = $this->runTasks(
            $tasks,
            $logPath,
            $rlogPath,
            $htmlPath,
            $htmlBody,
            $extractDir,
            $host,
            $startTime,
            $baseName
        );

        return [
            'success' => $taskResult['success'],
            'error'   => $taskResult['error'],
            'logFile' => $baseName . '.log',
        ];
    }

    // ── Extraction ────────────────────────────────────────────────────────────

    public function extract(string $file, string $destDir): bool
    {
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));

        if ($ext === 'zip') {
            $zip = new \ZipArchive();
            if ($zip->open($file) === true) {
                $zip->extractTo($destDir);
                $zip->close();
                return true;
            }
            return false;
        }

        if ($ext === 'rar') {
            exec('unrar x -o+ ' . escapeshellarg($file) . ' ' . escapeshellarg($destDir) . '/', $out, $code);
            if ($code === 0) return true;
        }

        exec('7z x ' . escapeshellarg($file) . ' -o' . escapeshellarg($destDir) . ' -y', $out, $code);
        return $code === 0;
    }

    // ── Internal task runner ──────────────────────────────────────────────────

    /**
     * @param array<int, array<string, mixed>> $tasks
     * @return array{success: bool, error: string}
     */
    private function runTasks(
        array $tasks,
        string $logPath,
        string $rlogPath,
        string $htmlPath,
        string $htmlBody,
        string $cwd,
        string $host,
        float $startTime,
        string $baseName
    ): array {
        $totalTasks  = count($tasks);
        $success     = true;
        $failedTask  = '';
        $errorOutput = '';
        $lastIndex   = 0;

        $this->logger->updateLiveStatus([
            'running'  => true,
            'task'     => 'Starting tasks…',
            'index'    => 0,
            'total'    => $totalTasks,
            'log_file' => $baseName . '.rlog',
        ]);

        foreach ($tasks as $index => $task) {
            $lastIndex   = $index;
            $taskName    = $task['name'] ?? 'Task #' . ($index + 1);
            $commands    = is_array($task['run']) ? $task['run'] : [$task['run']];
            $taskSuccess = true;

            // Task start
            $taskHeader = "\n[" . date('Y-m-d H:i:s') . "] +-- TASK " . ($index + 1) . "/$totalTasks: $taskName\n";
            $this->logger->appendRlog($rlogPath, $taskHeader);
            $this->logger->appendLog($logPath, $taskHeader);
            $htmlBody .= "<hr><h2>" . htmlspecialchars($taskName) . "</h2>\n";

            $this->logger->updateLiveStatus([
                'running'  => true,
                'task'     => $taskName,
                'index'    => $index + 1,
                'total'    => $totalTasks,
                'log_file' => $baseName . '.rlog',
            ]);

            foreach ($commands as $cmd) {
                $cmdHeader = '[' . date('Y-m-d H:i:s') . "] $ $cmd\n";
                $this->logger->appendRlog($rlogPath, $cmdHeader);
                $this->logger->appendLog($logPath, "[CMD] $cmd\n");
                $htmlBody .= "<h3>$ " . htmlspecialchars((string) $cmd) . "</h3>\n<pre><code>";

                $result = $this->runner->run(
                    (string) $cmd,
                    $cwd,
                    function (string $type, string $chunk) use ($rlogPath): void {
                        $this->logger->appendRlog($rlogPath, $chunk);
                    }
                );

                $this->logger->appendLog(
                    $logPath,
                    "STDOUT: {$result['stdout']}STDERR: {$result['stderr']}EXIT: {$result['exitCode']}\n"
                );

                $htmlBody .= htmlspecialchars($result['stdout']);
                if ($result['stderr']) {
                    $htmlBody .= "<span class='err'>" . htmlspecialchars($result['stderr']) . "</span>";
                }
                $htmlBody .= "</code></pre>\n";

                $hasError = $result['exitCode'] !== 0
                    || (bool) preg_match('/(npm error|error:|failed|Error:|ERR!)/i', $result['stderr']);

                if ($hasError) {
                    $taskSuccess = false;
                    $errorOutput = $result['stderr'] ?: $result['stdout'];
                    break;
                }
            }

            if ($taskSuccess) {
                $line = '[' . date('Y-m-d H:i:s') . "] ✓ TASK DONE: $taskName\n";
                $this->logger->appendRlog($rlogPath, $line);
                $this->logger->appendLog($logPath, $line);
            } else {
                $success    = false;
                $failedTask = $taskName;
                $line = '[' . date('Y-m-d H:i:s') . "] ✗ TASK FAILED: $taskName\n";
                $this->logger->appendRlog($rlogPath, $line);
                $this->logger->appendLog($logPath, $line);
                break;
            }
        }

        // Finalize
        $duration = round(microtime(true) - $startTime, 2);
        $status   = $success ? 'SUCCESS' : "FAILED at: $failedTask";
        $footer   = '[' . date('Y-m-d H:i:s') . "] === ARTIFACT DEPLOY $status ({$duration}s) ===\n";

        $this->logger->appendRlog($rlogPath, $footer);
        $this->logger->appendLog($logPath, $footer);

        $htmlBody .= "<hr><h2 class='" . ($success ? 'ok' : 'err') . "'>$status — {$duration}s</h2>\n";
        $this->logger->writeHtml($htmlPath, "Artifact Deploy — $status", $htmlBody);

        $this->logger->updateLiveStatus([
            'running'  => false,
            'finished' => true,
            'success'  => $success,
            'task'     => $success ? 'Artifact deploy finished successfully' : "Failed: $failedTask",
            'index'    => $lastIndex + 1,
            'total'    => $totalTasks,
            'duration' => $duration,
            'log_file' => $baseName . '.rlog',
        ]);

        $logUrl = 'http://' . $host . '/log/rview/' . $baseName;
        $this->notifier->send(
            $this->notifier->buildReport($host, $success, $duration, $logUrl, $failedTask, $errorOutput)
        );

        return ['success' => $success, 'error' => $errorOutput];
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** @return array{success: bool, error: string, logFile: string} */
    private function failure(string $error, string $logFile): array
    {
        return ['success' => false, 'error' => $error, 'logFile' => $logFile];
    }

    private function writeFailure(
        string $msg,
        string $logPath,
        string $rlogPath,
        string $htmlPath,
        string $htmlBody,
        string $baseName,
        float $startTime,
        string $host,
        string $task = 'Artifact Deploy'
    ): array {
        $duration = round(microtime(true) - $startTime, 2);
        $footer   = '[' . date('Y-m-d H:i:s') . "] === FAILED: $msg ({$duration}s) ===\n";

        $this->logger->appendRlog($rlogPath, $footer);
        $this->logger->appendLog($logPath, $footer);

        $htmlBody .= "<hr><h2 class='err'>FAILED: " . htmlspecialchars($msg) . "</h2>\n";
        $this->logger->writeHtml($htmlPath, 'Artifact Deploy — FAILED', $htmlBody);

        $this->logger->updateLiveStatus([
            'running'  => false,
            'finished' => true,
            'success'  => false,
            'task'     => "FAILED: $msg",
            'duration' => $duration,
            'log_file' => $baseName . '.rlog',
        ]);

        $logUrl = 'http://' . $host . '/log/rview/' . $baseName;
        $this->notifier->send(
            $this->notifier->buildReport($host, false, $duration, $logUrl, $task, $msg)
        );

        return $this->failure($msg, $baseName . '.log');
    }

    /** @return array{code: int, error: string} */
    private function curlDownload(string $url, string $destFile, string $token): array
    {
        $fp = fopen($destFile, 'w');
        if (!$fp) {
            return ['code' => 0, 'error' => "Cannot write to: $destFile"];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE           => $fp,
            CURLOPT_HTTPHEADER     => ["PRIVATE-TOKEN: $token"],
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_FAILONERROR    => true,
        ]);
        $ok       = curl_exec($ch);
        $error    = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);

        return ['code' => $ok ? $httpCode : 0, 'error' => $error];
    }
}
