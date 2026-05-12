<?php

declare(strict_types=1);

namespace Sphpd\Actions;

use Sphpd\Core\Config;

/**
 * Status endpoints: /status/check  /status/data  /status/live
 */
class StatusAction
{
    private Config $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    private function statusFile(): string
    {
        return $this->config->get('logs_path') . '/.current_status';
    }

    /** GET /status/check — returns {"finished": bool} */
    public function check(): void
    {
        $file = $this->statusFile();
        header('Content-Type: application/json');
        if (!file_exists($file)) {
            echo json_encode(['finished' => true]);
            return;
        }
        $data = json_decode((string) file_get_contents($file), true);
        echo json_encode(['finished' => isset($data['finished']) && $data['finished'] === true]);
    }

    /** GET /status/data — returns full status JSON */
    public function data(): void
    {
        $file = $this->statusFile();
        header('Content-Type: application/json');
        if (!file_exists($file)) {
            echo json_encode(['finished' => true]);
            return;
        }
        echo (string) file_get_contents($file);
    }

    /** GET /status/live — renders live-status HTML via Logger */
    public function live(string $logsPath): void
    {
        $file = $this->statusFile();
        if (!file_exists($file)) {
            echo '<pre>No deployment in progress.</pre>';
            return;
        }
        $liveFile = $logsPath . '/.live_status';
        if (file_exists($liveFile)) {
            header('Content-Type: text/html');
            readfile($liveFile);
            return;
        }
        echo '<pre>Waiting for status...</pre>';
    }

    /** POST /deploy/stop — kills running deployment */
    public function stop(): void
    {
        $file = $this->statusFile();
        header('Content-Type: application/json');

        if (!file_exists($file)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'No deployment in progress']);
            return;
        }

        $data = json_decode((string) file_get_contents($file), true);

        if (!isset($data['running']) || !$data['running']) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'No deployment running']);
            return;
        }

        if (!empty($data['pid'])) {
            $pid = (int) $data['pid'];
            shell_exec("kill -TERM $pid 2>/dev/null; sleep 1; kill -KILL $pid 2>/dev/null");
        }

        shell_exec("pkill -f 'stdbuf -o0 -e0 bash' 2>/dev/null");

        $data['running']    = false;
        $data['finished']   = true;
        $data['stopped_at'] = date('Y-m-d H:i:s');
        $data['task']       = 'DEPLOYMENT STOPPED BY USER';

        file_put_contents($file, json_encode($data));

        echo json_encode(['success' => true, 'message' => 'Deployment stopped']);
    }
}
