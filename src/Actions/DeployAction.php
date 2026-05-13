<?php

declare(strict_types=1);

namespace Sphpd\Actions;

use Sphpd\Core\Config;
use Sphpd\Core\Security;
use Sphpd\Domain\Deployment\ArtifactDeployment;
use Sphpd\Domain\Deployment\Deployment;
use Sphpd\Domain\Logger\Logger;

/**
 * Webhook deploy endpoints + SSE live stream.
 *
 * /webhook/deploy
 * /webhook/deploy/nowait
 * /webhook/artifact-deploy
 * /webhook/artifact-deploy/nowait
 * /status/stream   — SSE real-time log stream
 * /debugdeploy     (non-production only)
 */
class DeployAction
{
    private Config $config;
    private Security $security;
    private Deployment $deployment;
    private ArtifactDeployment $artifactDeployment;
    private Logger $logger;
    private string $entryScript;

    public function __construct(
        Config $config,
        Security $security,
        Deployment $deployment,
        ArtifactDeployment $artifactDeployment,
        Logger $logger,
        string $entryScript
    ) {
        $this->config = $config;
        $this->security = $security;
        $this->deployment = $deployment;
        $this->artifactDeployment = $artifactDeployment;
        $this->logger = $logger;
        $this->entryScript = $entryScript;
    }

    // ── Deploy endpoints ──────────────────────────────────────────────────────

    /** POST /webhook/deploy  (GET with ?manual=1 also accepted) */
    public function webhookDeploy(): void
    {
        $this->security->assertValid();

        $rawInput = (string) file_get_contents('php://input');
        $logFilePath = $this->logger->getLogsPath().'/req_'.date('Ymd_His').'.log';
        $this->logger->logRequest($logFilePath, $rawInput, $_SERVER, $_GET, $_POST);

        $manual = isset($_GET['manual']) && '1' === $_GET['manual'];
        $method = $this->method();
        $configMethod = strtoupper((string) $this->config->get('webhook_method'));

        if ($method !== $configMethod && !($manual && 'GET' === $method)) {
            http_response_code(405);

            exit('Method Not Allowed');
        }

        if ($manual) {
            $host = $this->host();
            $crashLog = $this->logger->getLogsPath().'/deploy_crash_'.date('Ymd_His').'.log';
            exec('php '.escapeshellarg($this->entryScript).' run-deploy '.escapeshellarg($host).' >> '.escapeshellarg($crashLog).' 2>&1 &');
            usleep(300000);
            header('Location: /status/live');

            exit;
        }

        $this->deployment->run($this->host());
    }

    /** POST /webhook/deploy/nowait */
    public function webhookDeployNoWait(): void
    {
        $this->security->assertValid();

        $rawInput = (string) file_get_contents('php://input');
        $logFilePath = $this->logger->getLogsPath().'/req_'.date('Ymd_His').'.log';
        $this->logger->logRequest($logFilePath, $rawInput, $_SERVER, $_GET, $_POST);

        if ($this->method() !== strtoupper((string) $this->config->get('webhook_method'))) {
            http_response_code(405);

            exit('Method Not Allowed');
        }

        $host = $this->host();
        $crashLog = $this->logger->getLogsPath().'/deploy_crash_'.date('Ymd_His').'.log';
        exec('php '.escapeshellarg($this->entryScript).' run-deploy '.escapeshellarg($host).' >> '.escapeshellarg($crashLog).' 2>&1 &');

        http_response_code(202);
        header('Content-Type: application/json');
        echo json_encode(['status' => 'accepted', 'message' => 'Deployment initiated in background']);
    }

    /** POST /webhook/artifact-deploy */
    public function webhookArtifactDeploy(): void
    {
        $contract = $this->checkArtifact();
        $this->artifactDeployment->run(
            $contract['project_id'],
            $contract['job'],
            $contract['job_id'],
            $contract['branch']
        );
    }

    /** POST /webhook/artifact-deploy/nowait */
    public function webhookArtifactDeployNoWait(): void
    {
        $this->security->assertValid();

        if ('POST' !== $this->method()) {
            http_response_code(405);

            exit('Method Not Allowed');
        }

        $contract = $this->parseArtifactInput();
        $host = $this->host();

        exec(
            'php '.escapeshellarg($this->entryScript)
            .' run-artifact-deploy '
            .escapeshellarg($host).' '
            .escapeshellarg((string) $contract['project_id']).' '
            .escapeshellarg((string) $contract['branch']).' '
            .escapeshellarg((string) $contract['job']).' '
            .escapeshellarg((string) $contract['job_id'])
            .' > /dev/null 2>&1 &'
        );

        http_response_code(202);
        header('Content-Type: application/json');
        echo json_encode(['status' => 'accepted', 'message' => 'Artifact deployment initiated in background']);
    }

    /** GET /debugdeploy (non-production only) */
    public function debugDeploy(): void
    {
        $this->deployment->run($this->host());
    }

    // ── SSE live stream ───────────────────────────────────────────────────────

    /**
     * GET /status/stream.
     *
     * Server-Sent Events endpoint. Streams the .rlog file of the current
     * (or most recent) deployment in real time. Supports reconnection via the
     * Last-Event-ID header — the client sends the last byte offset it received,
     * so the server can resume from there.
     *
     * Event types emitted:
     *   status  — current task/index/total/running from .current_status
     *   log     — one chunk of raw output from the .rlog file
     *   done    — deployment finished (success + duration)
     *   wait    — no active deployment, client should keep polling
     */
    public function stream(): void
    {
        // Disable output buffering everywhere we can
        if (ob_get_level()) {
            ob_end_clean();
        }

        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('X-Accel-Buffering: no'); // disable nginx/apache buffering

        $statusFile = $this->logger->getStatusFile();
        $logsPath = $this->logger->getLogsPath();

        // Resume from last known byte offset (reconnection support)
        $lastEventId = $_SERVER['HTTP_LAST_EVENT_ID'] ?? '';
        $offset = is_numeric($lastEventId) ? (int) $lastEventId : 0;

        $rlogPath = '';
        $maxIdle = 120; // seconds before closing (client reconnects automatically)
        $idleStart = time();

        while (true) {
            if (connection_aborted()) {
                break;
            }

            // Read current status
            $status = [];
            if (file_exists($statusFile)) {
                $status = json_decode((string) file_get_contents($statusFile), true) ?? [];
            }

            // Resolve rlog path from status
            if (!empty($status['log_file']) && '' === $rlogPath) {
                $candidate = $logsPath.'/'.$status['log_file'];
                if (file_exists($candidate)) {
                    $rlogPath = $candidate;
                    $offset = is_numeric($lastEventId) ? (int) $lastEventId : 0;
                    $idleStart = time();
                }
            }

            // No deployment active yet — emit wait and hold
            if (empty($status) || (!isset($status['running']) && empty($rlogPath))) {
                $this->sseEvent('wait', ['message' => 'No active deployment'], null);
                $this->sseSleep(2000);

                if (time() - $idleStart > $maxIdle) {
                    break;
                }

                continue;
            }

            // Stream new rlog content since last offset
            $hadOutput = false;
            if ('' !== $rlogPath && file_exists($rlogPath)) {
                clearstatcache(true, $rlogPath);
                $fileSize = filesize($rlogPath);
                if ($fileSize > $offset) {
                    $fp = fopen($rlogPath, 'rb');
                    fseek($fp, $offset);
                    $chunk = fread($fp, $fileSize - $offset);
                    fclose($fp);
                    $offset = $fileSize;
                    $idleStart = time();

                    if (false !== $chunk && '' !== $chunk) {
                        $hadOutput = true;
                        $this->sseEvent('log', ['text' => $chunk], (string) $offset);
                    }
                }
            }

            // Always emit current status so the UI can update task progress
            if (!empty($status)) {
                $this->sseEvent('status', [
                    'running'    => $status['running']    ?? false,
                    'task'       => $status['task']       ?? '',
                    'index'      => $status['index']      ?? 0,
                    'total'      => $status['total']      ?? 0,
                    'task_names' => $status['task_names'] ?? [],
                ], null);
            }

            // Deployment finished — emit done and close
            if (isset($status['finished']) && true === $status['finished']) {
                $this->sseEvent('done', [
                    'success' => $status['success'] ?? false,
                    'duration' => $status['duration'] ?? 0,
                    'log_file' => $status['log_file'] ?? '',
                ], null);

                break;
            }

            // Dead process guard — running=true but PID no longer exists → crashed
            if (!empty($status['running']) && !empty($status['pid'])) {
                $pid = (int) $status['pid'];
                if ($pid > 0 && !file_exists("/proc/{$pid}")) {
                    $this->sseEvent('log', ['text' => "\n[FATAL] Deploy process (PID {$pid}) died unexpectedly.\n"], null);
                    $this->sseEvent('done', [
                        'success' => false,
                        'duration' => 0,
                        'log_file' => $status['log_file'] ?? '',
                    ], null);
                    // Mark status as finished so next page load doesn't get stuck
                    $this->logger->updateLiveStatus([
                        'running' => false,
                        'finished' => true,
                        'success' => false,
                    ]);

                    break;
                }
            }

            // Idle timeout guard
            if (time() - $idleStart > $maxIdle) {
                break;
            }

            // Adaptive sleep: no delay if there was output, short pause otherwise
            if (!$hadOutput) {
                $this->sseSleep(200);
            }
        }
    }

    // ── SSE helpers ───────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $data
     */
    private function sseEvent(string $event, array $data, ?string $id): void
    {
        if (null !== $id) {
            echo "id: {$id}\n";
        }
        echo "event: {$event}\n";
        echo 'data: '.json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n\n";
        flush();
    }

    private function sseSleep(int $milliseconds): void
    {
        usleep($milliseconds * 1000);
    }

    // ── Artifact helpers ──────────────────────────────────────────────────────

    /**
     * Validates security, method, reads + logs raw input, validates fields.
     *
     * @return array{project_id:string,branch:string,job:string,job_id:string}
     */
    private function checkArtifact(): array
    {
        $this->security->assertValid();

        if ('POST' !== $this->method()) {
            http_response_code(405);

            exit('Method Not Allowed');
        }

        $rawInput = (string) file_get_contents('php://input');
        $logFilePath = $this->logger->getLogsPath().'/req_'.date('Ymd_His').'.log';
        $this->logger->logRequest($logFilePath, $rawInput, $_SERVER, $_GET, $_POST);

        return $this->parseArtifactInput($rawInput);
    }

    /**
     * @param null|string $rawInput pre-read body; reads php://input if null
     *
     * @return array{project_id:string,branch:string,job:string,job_id:string}
     */
    private function parseArtifactInput(?string $rawInput = null): array
    {
        if (null === $rawInput) {
            $rawInput = (string) file_get_contents('php://input');
        }

        $input = json_decode($rawInput, true) ?? [];
        $projectId = $input['project_id'] ?? null;
        $branch = $input['branch'] ?? 'main';
        $job = $input['job'] ?? 'some';
        $jobId = $input['job_id'] ?? null;

        if (!$projectId || !$jobId) {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Missing required fields: project_id, job_id']);

            exit;
        }

        return [
            'project_id' => (string) $projectId,
            'branch' => (string) $branch,
            'job' => (string) $job,
            'job_id' => (string) $jobId,
        ];
    }

    // ── Internal helpers ──────────────────────────────────────────────────────

    private function dashboardUrl(string $query = ''): string
    {
        $route = (string) $this->config->get('dashboard_route');

        return '/'.$route.($query ? '?'.$query : '');
    }

    private function host(): string
    {
        return $_SERVER['HTTP_HOST'] ?? 'localhost';
    }

    private function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }
}
