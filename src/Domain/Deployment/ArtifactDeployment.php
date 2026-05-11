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

        // Concurrent deployment guard
        if (file_exists($this->statusFile)) {
            $current = json_decode(file_get_contents($this->statusFile), true);
            if (!isset($current['finished']) || $current['finished'] !== true) {
                return $this->failure('Deployment already in progress.', '');
            }
            unlink($this->statusFile);
        }

        $logFileName = 'artifact_deploy_'.date('Ymd_His').'.log';
        $logsPath    = $this->config->get('logs_path');
        $logFilePath = $logsPath.'/'.$logFileName;
        $logRawPath  = $logFilePath.'.rlog';
        $logHtmlPath = $logFilePath.'.html';
        $logFRawPath = $logFilePath.'.fraw';
        $startTime   = microtime(true);

        // Lock status immediately to prevent race conditions
        $this->logger->updateLiveStatus([
            'running'  => true,
            'finished' => false,
            'task'     => 'Downloading artifact...',
            'index'    => 0,
            'total'    => 0,
            'pid'      => getmypid(),
            'log_file' => $logFileName,
            'start'    => $startTime,
        ]);

        $preLog    = 'START: '.date('Y-m-d H:i:s')."\n";
        $preLogRaw = 'START: '.date('Y-m-d H:i:s')."\n";
        file_put_contents($logFRawPath, $preLog);

        $logLine = function (string $line, string $level = 'info') use (&$preLog, &$preLogRaw, $logFRawPath) {
            $ts = date('Y-m-d H:i:s');
            $preLog    .= "[PRE][$level] $line\n";
            $preLogRaw .= "[$ts][$level] $line\n";
            file_put_contents($logFRawPath, "[PRE][$level] $line\n", FILE_APPEND);
        };

        // Validate config
        $gitlabToken = $this->config->get('gitlab_token');
        if (empty($gitlabToken)) {
            $logLine('GITLAB_TOKEN not configured', 'error');
            return $this->writeFailure('GITLAB_TOKEN not configured', $preLog, $preLogRaw,
                $logFilePath, $logRawPath, $logHtmlPath, $logFRawPath, $logFileName, $startTime, $host);
        }

        $instructionsFile = $this->config->get('artifact_instructions');
        if (!file_exists($instructionsFile)) {
            $msg = "Artifact instructions file not found: $instructionsFile";
            $logLine($msg, 'error');
            return $this->writeFailure($msg, $preLog, $preLogRaw,
                $logFilePath, $logRawPath, $logHtmlPath, $logFRawPath, $logFileName, $startTime, $host);
        }

        // Prepare deploy directory
        $deployDir = $this->config->get('artifact_deploy_dir');
        if (!is_dir($deployDir)) {
            mkdir($deployDir, 0755, true);
        }

        // Download
        $artifactFile = $deployDir.'/artifact.zip';
        $gitlabBase   = rtrim($this->config->get('gitlab_base_url'), '/');
        $artifactUrl  = "{$gitlabBase}/api/v4/projects/{$projectId}/jobs/{$jobId}/artifacts";

        $logLine("Downloading artifact — project: $projectId | branch: $branch | job: $job");
        $logLine("URL: $artifactUrl");

        $dl = ($this->downloader)($artifactUrl, $artifactFile, $gitlabToken);

        if ($dl['code'] !== 200 || $dl['error'] !== '') {
            $msg = "Artifact download failed (HTTP {$dl['code']}): {$dl['error']}";
            $logLine($msg, 'error');
            return $this->writeFailure($msg, $preLog, $preLogRaw,
                $logFilePath, $logRawPath, $logHtmlPath, $logFRawPath, $logFileName, $startTime, $host, 'Download');
        }

        $fileSize = round(filesize($artifactFile) / 1024, 1);
        $logLine("Artifact downloaded successfully — {$fileSize} KB");

        // Extract
        $this->logger->updateLiveStatus([
            'running'  => true,
            'finished' => false,
            'task'     => 'Extracting artifact...',
            'log_file' => $logFileName,
        ]);

        $extractDir = $deployDir.'/extracted';
        if (is_dir($extractDir)) {
            exec('rm -rf '.escapeshellarg($extractDir));
        }
        mkdir($extractDir, 0755, true);

        $logLine("Extracting artifact to: $extractDir");

        if (!$this->extract($artifactFile, $extractDir)) {
            $logLine('Failed to extract artifact', 'error');
            return $this->writeFailure('Failed to extract artifact', $preLog, $preLogRaw,
                $logFilePath, $logRawPath, $logHtmlPath, $logFRawPath, $logFileName, $startTime, $host, 'Extract');
        }

        $logLine('Artifact extracted successfully');

        // Load and validate instructions
        $result = $this->instructions->load($instructionsFile);
        if (isset($result['error'])) {
            $logLine($result['error'], 'error');
            return $this->writeFailure($result['error'], $preLog, $preLogRaw,
                $logFilePath, $logRawPath, $logHtmlPath, $logFRawPath, $logFileName, $startTime, $host);
        }

        $tasks = $result['tasks'];
        $logLine('Instructions validated — '.count($tasks).' task(s) queued');
        $logLine("Starting task execution in: $extractDir");

        // Run tasks
        $taskResult = $this->runTasks(
            $tasks, $logFilePath, $logRawPath, $logHtmlPath, $logFRawPath,
            $extractDir, $host, $startTime, $logFileName, $preLog, $preLogRaw
        );

        return [
            'success' => $taskResult['success'],
            'error'   => $taskResult['error'],
            'logFile' => $logFileName,
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
            exec('unrar x -o+ '.escapeshellarg($file).' '.escapeshellarg($destDir).'/', $out, $code);
            if ($code === 0) return true;
        }

        exec('7z x '.escapeshellarg($file).' -o'.escapeshellarg($destDir).' -y', $out, $code);
        return $code === 0;
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
        string $logFileName,
        string $preLog,
        string $preLogRaw
    ): array {
        $totalTasks  = count($tasks);
        $taskStatus  = [];
        $success     = true;
        $failedTask  = '';
        $errorOutput = '';
        $lastIndex   = 0;

        foreach ($tasks as $i => $t) {
            $taskStatus[$i] = ['name' => $t['name'] ?? 'Task '.($i + 1), 'status' => 'pending', 'output' => ''];
        }

        $this->logger->updateLiveStatus([
            'running' => true, 'task' => 'Starting...', 'index' => 0,
            'total' => $totalTasks, 'start' => $startTime, 'history' => $taskStatus,
        ]);

        $fullLog     = $preLog.'START TASKS: '.date('Y-m-d H:i:s')."\n";
        $fullLogRaw  = $preLogRaw.'START TASKS: '.date('Y-m-d H:i:s')."\n";
        $htmlContent = '<h1>Artifact Deploy Log - Started at '.date('Y-m-d H:i:s')."</h1>\n";
        file_put_contents($logFRawPath, "START TASKS: ".date('Y-m-d H:i:s')."\n", FILE_APPEND);

        foreach ($tasks as $index => $task) {
            $lastIndex  = $index;
            $taskName   = $task['name'] ?? 'Task #'.($index + 1);
            $commands   = is_array($task['run']) ? $task['run'] : [$task['run']];

            $taskStatus[$index]['status'] = 'running';
            $this->logger->updateLiveStatus([
                'running' => true, 'task' => $taskName,
                'index' => $index + 1, 'total' => $totalTasks,
                'start' => $startTime, 'history' => $taskStatus, 'current_output' => '',
            ]);

            $fullLog     .= "\n+---------------------------------------------+\n[TASK]: $taskName\n";
            $htmlContent .= "<hr><h2>$taskName</h2>\n";

            $taskSuccess = true;

            foreach ($commands as $cmd) {
                $result      = $this->runner->run($cmd, $cwd);
                $fullLog     .= "[CMD]: $cmd\nSTDOUT: {$result['stdout']}\nSTDERR: {$result['stderr']}\nEXIT: {$result['exitCode']}\n";
                $fullLogRaw  .= '['.date('Y-m-d H:i:s')."] $cmd\n[info  ] {$result['stdout']}";
                $htmlContent .= "<pre><code>".htmlspecialchars($result['stdout']);

                if ($result['stderr']) {
                    $htmlContent .= "<span style='color:red;'>".htmlspecialchars($result['stderr'])."</span>";
                }
                $htmlContent .= "</code></pre>\n";

                $taskStatus[$index]['output'] .= $result['stdout'].$result['stderr'];
                $this->logger->updateLiveStatus([
                    'running' => true, 'task' => $taskName, 'index' => $index + 1,
                    'total' => $totalTasks, 'start' => $startTime, 'history' => $taskStatus,
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

            if ($taskSuccess) {
                $taskStatus[$index]['status'] = 'success';
            } else {
                $success    = false;
                $failedTask = $taskName;
                $taskStatus[$index]['status'] = 'failed';
                $this->logger->updateLiveStatus([
                    'running' => false, 'task' => "FAILED: $taskName", 'history' => $taskStatus,
                ]);
                break;
            }
        }

        $duration    = round(microtime(true) - $startTime, 2);
        $htmlContent .= "<h2>Finished in {$duration}s</h2><p>".($success ? 'SUCCESS' : "FAILED at $failedTask")."</p>\n";

        file_put_contents($logFilePath, $fullLog."\nEND. Duration: {$duration}s");
        file_put_contents($logRawPath, $fullLogRaw."\nEND. Duration: {$duration}s");
        file_put_contents($logFRawPath, "\nEND. Duration: {$duration}s", FILE_APPEND);
        file_put_contents($logHtmlPath, $this->logger->buildHtml('Artifact Deploy Log', $htmlContent));

        $this->logger->updateLiveStatus([
            'running' => false, 'finished' => true, 'success' => $success,
            'task' => $success ? 'Artifact Deploy Finished' : 'Artifact Deploy Failed',
            'index' => $lastIndex + 1, 'total' => $totalTasks,
            'duration' => $duration, 'history' => $taskStatus, 'log_file' => basename($logFilePath),
        ]);

        $logId  = pathinfo($logFilePath, PATHINFO_FILENAME);
        $logUrl = "http://{$host}/log/rview/{$logId}";
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
        string $preLog,
        string $preLogRaw,
        string $logFilePath,
        string $logRawPath,
        string $logHtmlPath,
        string $logFRawPath,
        string $logFileName,
        float $startTime,
        string $host,
        string $task = 'Artifact Deploy'
    ): array {
        $duration = round(microtime(true) - $startTime, 2);
        $logId    = pathinfo($logFilePath, PATHINFO_FILENAME);
        $logUrl   = "http://{$host}/log/rview/{$logId}";

        file_put_contents($logFilePath, $preLog."\nERROR: $msg\nEND. Duration: {$duration}s");
        file_put_contents($logRawPath, $preLogRaw."\nERROR: $msg\nEND. Duration: {$duration}s");
        file_put_contents($logFRawPath, "[PRE][error] $msg\nEND. Duration: {$duration}s\n", FILE_APPEND);
        file_put_contents($logHtmlPath, $this->logger->buildHtml(
            'Artifact Deploy Log',
            '<h1>Artifact Deploy Log</h1><p style="color:red;">ERROR: '.htmlspecialchars($msg).'</p>'
        ));

        $this->logger->updateLiveStatus([
            'running' => false, 'finished' => true, 'success' => false,
            'task' => "FAILED: $msg", 'duration' => $duration, 'log_file' => $logFileName,
        ]);

        $this->notifier->send(
            $this->notifier->buildReport($host, false, $duration, $logUrl, $task, $msg)
        );

        return $this->failure($msg, $logFileName);
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
