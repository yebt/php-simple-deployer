<?php

/**
 * Simple PHP Deployer
 * Minimalist Technical UI - PHP 8.5+
 * Format: JSON Instructions
 */

// 1. Load .env
if (file_exists(__DIR__.'/.env')) {
    $lines = file(__DIR__.'/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0)
            continue;
        [$name, $value] = explode('=', $line, 2);
        $_ENV[trim($name)] = trim($value);
    }
}

// Configuration with Defaults
$defaults = [
    // 'PROJECT_PATH' => [__DIR__, true],
    'INSTRUCTIONS_FILE' => ['deploy.json', true],
    'LOGS_PATH' => ['./logs', true],
    'WEBHOOK_METHOD' => ['POST', true],
];

$config = [
    // 'project_path' => $_ENV['PROJECT_PATH'] ?? $defaults['PROJECT_PATH'][0],
    'project_path' => $_ENV['PROJECT_PATH'] ?? null,
    'instructions' => $_ENV['INSTRUCTIONS_FILE'] ?? $defaults['INSTRUCTIONS_FILE'][0],
    'logs_path' => $_ENV['LOGS_PATH'] ?? $defaults['LOGS_PATH'][0],
    'bot_token' => $_ENV['TELEGRAM_BOT_TOKEN'] ?? '',
    'chat_id' => $_ENV['TELEGRAM_CHAT_ID'] ?? '',
    'thread_id' => $_ENV['TELEGRAM_THREAD_ID'] ?? null,
    'secure_token' => $_ENV['SECURITY_TOKEN'] ?? '',
    'webhook_method' => $_ENV['WEBHOOK_METHOD'] ?? $defaults['WEBHOOK_METHOD'][0],
];

// Ensure logs directory exists
if (! is_dir($config['logs_path'])) {
    mkdir($config['logs_path'], 0777, true);
}

// Ensure paths with realpaths
foreach (['project_path', 'logs_path'] as $key) {
    $config[$key] = realpath($config[$key]);
}

$statusFile = $config['logs_path'].'/.current_status';

// Execute the deployment if called from CLI with the specific argument
if (isset($argv[1]) && $argv[1] === 'run-deploy') {
    define('CLI_HOST', $argv[2] ?? 'localhost');
    executeDeployment();
    exit();
}

// 2. Router
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

switch ($uri) {
    case '/status/check':
        header('Content-Type: application/json');
        if (! file_exists($statusFile)) {
            echo json_encode(['finished' => true]);
        } else {
            $data = json_decode(file_get_contents($statusFile), true);
            echo json_encode(['finished' => isset($data['finished']) && $data['finished'] === true]);
        }
        exit();
    case '/':
        // redurect to health view
        header('Location: /health');
        break;
    case '/health':
        renderHealthView();
        break;
    case '/webhook/deploy':
        validateSecurity();
        $manual = isset($_GET['manual']) && $_GET['manual'] == '1';
        if ($method !== $config['webhook_method'] && ! ($manual && $method === 'GET')) {
            http_response_code(405);
            exit('Method Not Allowed');
        }

        if (isset($_GET['manual']) && $_GET['manual'] == '1') {
            // Ejecutamos el script de despliegue en segundo plano
            // exec('php '.__FILE__.' run-deploy > /dev/null 2>&1 &');

            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            exec('php '.__FILE__.' run-deploy '.escapeshellarg($host).' > /dev/null 2>&1 &');
            // wait lock file
            usleep(500000);
            header('Location: /health');
            exit();
        }

        executeDeployment();
        break;
    case '/log/view':
        showSpecificLog($_GET['file'] ?? '');
        break;
    case '/log/last':
        showLastLog();
        break;
    case '/status/live':
        renderLiveStatus();
        break;
    case '/test-notify':
        $res = sendTelegram('Simple PHP Deployer: Test Notification');
        header('Location: /health?notified='.($res ? '1' : '0'));
        break;
    case '/clear-history':
        clearHistory();
        break;
}

// --- LOGIC ---

function validateSecurity()
{
    global $config;
    if (empty($config['secure_token']))
        return;
    if (isset($_GET['manual']) && $_GET['manual'] === '1')
        return;
    $headers = getallheaders();
    $token = $headers['X-Deploy-Token'] ?? $_GET['token'] ?? '';
    if ($token !== $config['secure_token']) {
        http_response_code(401);
        exit('Unauthorized');
    }
}

function executeDeployment()
{
    global $config, $statusFile;

    // Validate required config variables
    $requiredVars = [
        'project_path' => $config['project_path'],
        'instructions' => $config['instructions'],
    ];
    foreach ($requiredVars as $label => $value) {
        if (empty($value) || ! file_exists($value) && $label === 'project_path') {
            http_response_code(400);
            exit("Error: Configuración inválida o faltante: $label");
        }
    }

    if (file_exists($statusFile)) {
        $current = json_decode(file_get_contents($statusFile), true);
        // Si el proceso anterior ya terminó, borramos el lock viejo para permitir el nuevo
        if (isset($current['finished']) && $current['finished'] === true) {
            unlink($statusFile);
        } else {
            http_response_code(409);
            exit('Deployment already in progress.');
        }
    }
    // if (file_exists($statusFile)) {
    //     http_response_code(409);
    //     exit('Deployment already in progress.');
    // }

    $startTime = microtime(true);
    $logFilename = 'deploy_'.date('Ymd_His').'.log';
    $logPath = $config['logs_path'].'/'.$logFilename;
    if (! file_exists($config['instructions']))
        exit('Instruction file missing.');
    $jsonContent = file_get_contents($config['instructions']);
    $tasks = json_decode($jsonContent, true);
    if (json_last_error() !== JSON_ERROR_NONE)
        exit('Invalid JSON in instructions file.');

    file_put_contents($statusFile, json_encode([
        'running' => true,
        'task' => 'Starting...',
        'index' => 0,
        'total' => count($tasks),
        'start' => $startTime,
    ]));
    chdir($config['project_path']);
    $success = true;
    $failedTask = '';
    $fullLog = 'START: '.date('Y-m-d H:i:s')."\n";

    // Al inicio de executeDeployment, inicializa un array de estados
    // $taskStatus = array_fill(0, count($tasks), 'pending');

    $taskStatus = array_fill(0, count($tasks), ['status' => 'pending', 'name' => '']);
    foreach ($tasks as $i => $t) {
        $taskStatus[$i]['name'] = $t['name'] ?? 'Task '.($i + 1);
    }

    foreach ($tasks as $index => $task) {
        // $taskStatus[$index] = 'running';
        $taskStatus[$index]['status'] = 'running'; // Cambiamos solo el status

        $name = $task['name'] ?? 'Unnamed Task';
        $cmd = $task['run'] ?? '';

        file_put_contents($statusFile, json_encode([
            'running' => true,
            'task' => $name,
            'index' => $index + 1,
            'total' => count($tasks),
            'start' => $startTime,
            'history' => $taskStatus,
        ]));
        $fullLog .= "\n[TASK]: $name\n[CMD]: $cmd\n";
        $process = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        $exitCode = proc_close($process);
        $fullLog .= "STDOUT: $stdout\nSTDERR: $stderr\nEXIT: $exitCode\n";
        if ($exitCode !== 0) {
            $success = false;
            $failedTask = $name;

            // break;
        }
        if ($exitCode === 0) {
            // $taskStatus[$index] = 'success';
            $taskStatus[$index]['status'] = 'success';
        } else {
            // $taskStatus[$index] = 'failed';
            $taskStatus[$index]['status'] = 'failed';
            // Guardamos el último estado antes de salir por error
            file_put_contents($statusFile, json_encode([
                'running' => false, // Detener animación en live si falló
                'task' => "FAILED: $name",
                'index' => $index + 1,
                'total' => count($tasks),
                'history' => $taskStatus,
            ]));
            break;
        }
    }
    $duration = round(microtime(true) - $startTime, 2);
    file_put_contents($logPath, $fullLog."\nEND. Duration: {$duration}s");
    // unlink($statusFile);
    file_put_contents($statusFile, json_encode([
        'running' => false, // IMPORTANTE: Ya no está corriendo
        'finished' => true,
        'success' => $success,
        'task' => $success ? 'Deployment Finished Successfully' : 'Deployment Failed',
        'index' => $index + 1,
        'total' => count($tasks),
        'start' => $startTime,
        'duration' => $duration,
        'history' => $taskStatus,
        'log_file' => $logFilename,
    ]));

    $protocol = isset($_SERVER['HTTPS']) ? 'https://' : 'http://';
    // $logUrl = $protocol.$_SERVER['HTTP_HOST'].'/log/view?file='.urlencode($logFilename);
    //
    $host = defined('CLI_HOST') ? CLI_HOST : $_SERVER['HTTP_HOST'] ?? 'localhost';
    $logUrl = "$protocol{$host}/log/view?file=".urlencode($logFilename);

    sendTelegram(
        "Simple PHP Deployer Report\nStatus: "
        .($success ? 'SUCCESS' : "FAILED at $failedTask")
        ."\nDuration: {$duration}s\nLink: $logUrl",
    );
    if (! isset($_GET['manual']))
        echo 'Done.';

    // if (isset($_GET['manual']))
    //     header('Location: /health?deployed='.($success ? '1' : '0'));
    // else
    //     echo 'Done.';
}

function sendTelegram($text)
{
    global $config;
    if (empty($config['bot_token']))
        return false;
    $ch = curl_init("https://api.telegram.org/bot{$config['bot_token']}/sendMessage");
    curl_setopt($ch, CURLOPT_POSTFIELDS, [
        'chat_id' => $config['chat_id'],
        'text' => $text,
        // 'parse_mode' => 'MarkdownV2',
        'message_thread_id' => $config['thread_id'],
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    return curl_exec($ch);
}

function showSpecificLog($file)
{
    global $config;
    $path = realpath($config['logs_path'].'/'.$file);
    if ($path && str_starts_with($path, realpath($config['logs_path']))) {
        header('Content-Type: text/plain');
        readfile($path);
    } else
        exit('Access Denied');
}

function showLastLog()
{
    global $config;
    $logs = glob($config['logs_path'].'/*.log');
    if (! $logs)
        exit('No logs available.');
    usort($logs, fn ($a, $b) => filemtime($b) - filemtime($a));
    header('Content-Type: text/plain');
    readfile($logs[0]);
}

function clearHistory()
{
    global $config, $statusFile;

    $logs = glob($config['logs_path'].'/*.log');
    foreach ($logs as $log)
        unlink($log);

    if (file_exists($statusFile)) {
        $current = json_decode(file_get_contents($statusFile), true);
        if (isset($current['finished']) && $current['finished'] === true) {
            unlink($statusFile);
        } else {
            http_response_code(409);
            exit('Deployment already in progress.');
        }
    }
    header('Location: /health?cleared=1');
}

// --- VIEWS ---

function renderHeadImports()
{
    return <<<HTML
        <style>
            @font-face {
                font-family: 'JetBrains Mono Variable';
                font-style: normal;
                font-display: swap;
                font-weight: 100 800;
                src: url(https://cdn.jsdelivr.net/fontsource/fonts/jetbrains-mono:vf@latest/latin-wght-normal.woff2) format('woff2-variations');
            }
        </style>
        <style type="text/tailwindcss">
            @theme {
                --font-mono: "JetBrains Mono Variable", ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
            }
        </style>
        HTML;
}

function renderHealthView()
{
    global $config, $statusFile, $defaults;

    $serverIp = $_SERVER['SERVER_ADDR'] ?? 'Local';
    $serverDomain = $_SERVER['HTTP_HOST'] ?? 'Unknown Domain';
    $phpVersion = PHP_VERSION;
    $instructionExists = file_exists($config['instructions']);
    $logs = glob($config['logs_path'].'/*.log');
    usort($logs, fn ($a, $b) => filemtime($b) - filemtime($a));
    $lastLogs = array_slice($logs, 0, 5);
    $isDeploying = file_exists($statusFile);

    $statusData = file_exists($statusFile) ? json_decode(file_get_contents($statusFile), true) : null;
    $isActuallyRunning = $statusData && (! isset($statusData['finished']) || ! $statusData['finished']);

    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <script src="https://unpkg.com/@tailwindcss/browser@4"></script>
        <title>System Health - Deployer</title>
        <?= renderHeadImports() ?>      
        <script>
            <?php if ($isActuallyRunning): ?>
                const checkStatus = setInterval(async () => {
                    try {
                        // Hacemos un fetch a un endpoint que nos devuelva el status breve
                        const response = await fetch('/status/check');
                        const data = await response.json();

                        // Si el servidor dice que ya no corre, recargamos la página para actualizar el UI
                        if (data.finished === true) {
                            clearInterval(checkStatus);
                            window.location.reload();
                        }
                    } catch (e) {
                        console.error("Error checking status:", e);
                    }
                }, 2000); // Consulta cada 2 segundos
            <?php endif; ?>
        </script>
    </head>
    <body class="bg-[#f8fafc] dark:bg-[#0b0f1a] text-slate-600 dark:text-slate-300 p-8 font-mono text-sm transition-colors duration-200">
        <div class="max-w-6xl mx-auto">
            <div class="flex justify-between items-start mb-10">
                <div>
                    <h1 class="text-slate-900 dark:text-white font-bold text-xl tracking-tighter uppercase">SIMPLE PHP <span class="text-[#a855f7]">DEPLOYER</span></h1>
                    <p class="text-slate-400 dark:text-slate-500">
                        Host: <span class="text-slate-600 dark:text-slate-400"><?= $serverDomain ?></span> | 
                        IP: <span class="text-slate-600 dark:text-slate-400"><?= $serverIp ?></span> | 
                        PHP: <span class="text-slate-600 dark:text-slate-400"><?= $phpVersion ?></span>
                    </p>
                </div>
                <?php if ($isActuallyRunning): ?>
                    <a href="/status/live" class="bg-blue-600 text-white px-3 py-1 text-xs font-bold rounded shadow-lg shadow-blue-500/20 animate-pulse">PROCESS RUNNING</a>
                <?php endif; ?>
            </div>

            <?php if (! $instructionExists): ?>
                <div class="bg-amber-100 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-500/50 p-4 rounded mb-8 text-amber-700 dark:text-amber-200">
                    <span class="font-bold">ALERT:</span> Instruction file not found at "<?= $config['instructions'] ?>".
                </div>
            <?php endif; ?>

            <div class="grid grid-cols-1 lg:grid-cols-4 gap-6">
                <div class="lg:col-span-1 space-y-4">
                    <div class="bg-white dark:bg-[#161b2a] border border-slate-200 dark:border-slate-800 p-5 rounded-lg shadow-sm dark:shadow-xl text-xs">
                        <h2 class="text-slate-400 dark:text-slate-500 font-bold mb-4 border-b border-slate-100 dark:border-slate-800 pb-2 uppercase tracking-widest">System Config</h2>
                        <div class="space-y-4 text-[11px]">
                            <?php

                            $vars = [
                                'Path' => 'project_path',
                                'Instructions file' => 'instructions',
                                'Logs path' => 'logs_path',
                                'Token' => 'bot_token',
                                'Security' => 'secure_token',
                            ];
                            foreach ($vars as $label => $key):
                                $isSet = ! empty($config[$key]);
                                $isDefault =
                                    isset($defaults[strtoupper($key)])
                                    && $config[$key] === $defaults[strtoupper($key)][0];
                                ?>
                                <div class="flex flex-row items-center justify-between gap-4">
                                    <div class="flex items-center gap-1 min-w-0">
                                        <span class="text-slate-400 dark:text-slate-500 uppercase truncate"><?= $label ?></span>
                                    </div>

                                    <div>

                                        <?php if ($isDefault): ?>
                                            <span class="text-slate-400 dark:text-slate-600 font-bold tracking-tighter shrink-0">(DEFAULT)</span>
                                        <?php endif; ?>
                                        <span class="px-2 py-0.5 rounded font-bold shrink-0 <?= $isSet
                                            ? 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-500'
                                            : 'bg-rose-500/10 text-rose-600 dark:text-rose-500' ?>">
                                            <?= $isSet ? '0K' : '??' ?>
                                        </span>

                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-[#161b2a] border border-slate-200 dark:border-slate-800 p-5 rounded-lg shadow-sm dark:shadow-xl">
                        <h2 class="text-slate-400 dark:text-slate-500 text-[10px] font-bold mb-4 border-b border-slate-100 dark:border-slate-800 pb-2 uppercase tracking-widest">Quick Actions</h2>
                        <div class="space-y-2">

                            <?php if ($isActuallyRunning): ?>
                                <button disabled class="block w-full text-center bg-slate-100 dark:bg-slate-800 text-slate-400 dark:text-slate-600 py-2 rounded text-xs font-bold cursor-not-allowed uppercase tracking-widest">
                                    <span class="inline-block w-2 h-2 bg-blue-500 rounded-full animate-ping mr-2"></span>
                                    Running...
                                </button>
                            <?php else: ?>
                                <a href="/webhook/deploy?manual=1" 
                                    onclick="return confirm('Start deployment?')"
                                    class="block w-full text-center bg-slate-800 dark:bg-slate-800 text-white py-2 rounded text-xs font-bold hover:bg-slate-700 dark:hover:bg-slate-700 transition">MANUAL DEPLOY</a>

                            <?php endif; ?>

                            <?php if (isset($statusData['finished'])): ?>
                                <a href="/status/live" class="block w-full text-center border border-emerald-500/30 text-emerald-500 py-2 rounded text-[10px] font-bold hover:bg-emerald-500/5 transition uppercase">
                                    View Last Result
                                </a>
                            <?php endif; ?>
                            <a href="/test-notify" class="block w-full text-center border border-slate-200 dark:border-slate-800 hover:bg-slate-50 dark:hover:bg-slate-800 py-2 rounded text-xs transition">TEST NOTIFICATION</a>
                            <a href="/clear-history" onclick="return confirm('Clear all logs?')" class="block w-full text-center text-rose-500 hover:text-rose-500 py-2 text-xs transition">CLEAR HISTORY</a>
                        </div>
                    </div>
                </div>

                <div class="lg:col-span-3 bg-white dark:bg-[#161b2a] border border-slate-200 dark:border-slate-800 rounded-lg overflow-hidden shadow-sm dark:shadow-xl flex flex-col">
                    <div class="p-5 border-b border-slate-100 dark:border-slate-800 flex justify-between items-center bg-slate-50/50 dark:bg-slate-900/30">
                        <h2 class="text-slate-400 dark:text-slate-500 text-[10px] font-bold uppercase tracking-widest">Recent Execution History</h2>
                        <a href="/log/last" class="text-[10px] text-indigo-600 dark:text-indigo-400 hover:underline font-bold uppercase tracking-tighter transition">View Latest Raw</a>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left border-collapse">
                            <thead>
                                <tr class="bg-slate-50 dark:bg-slate-900/50 text-slate-400 dark:text-slate-500 text-[10px] uppercase">
                                    <th class="px-6 py-3 font-bold border-b border-slate-100 dark:border-slate-800">Log Identifier</th>
                                    <th class="px-6 py-3 font-bold border-b border-slate-100 dark:border-slate-800">Timestamp</th>
                                    <th class="px-6 py-3 font-bold text-right border-b border-slate-100 dark:border-slate-800">Reference</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                                <?php foreach ($lastLogs as $logPath):
                                    $fn = basename($logPath);
                                    $content = file_get_contents($logPath);
                                    $isOk = str_contains($content, 'Status: SUCCESS');
                                    ?>
                                    <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/20 transition">
                                        <td class="px-6 py-4 text-xs">
                                            <span class="mr-2 <?= $isOk ? 'text-emerald-500' : 'text-rose-500' ?>">●</span>
                                            <?= $fn ?>
                                        </td>
                                        <!-- <td class="px-6 py-4 text-slate-700 dark:text-slate-400 font-mono text-xs"><?= $fn ?></td> -->
                                        <td class="px-6 py-4 text-slate-400 dark:text-slate-600 text-xs"><?= date(
                                            'Y-m-d H:i:s',
                                            filemtime($logPath),
                                        ) ?></td>
                                        <td class="px-6 py-4 text-right">
                                            <a href="/log/view?file=<?= urlencode($fn) ?>" class="text-blue-600 dark:text-blue-500 hover:underline font-bold text-xs transition">OPEN</a>
                                        </td>
                                    </tr>
                                <?php endforeach;
                                if (! $lastLogs): ?>
                                    <tr><td colspan="3" class="p-10 text-center text-slate-400 italic">No execution history found.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            
            <div class="mt-8 bg-white dark:bg-[#161b2a] border border-slate-200 dark:border-slate-800 p-6 rounded-lg shadow-sm dark:shadow-xl">
                <h2 class="text-slate-400 dark:text-slate-500 text-[10px] font-bold mb-4 border-b border-slate-100 dark:border-slate-800 pb-2 uppercase tracking-widest">Example deploy.json</h2>
                <pre class="text-[11px] text-indigo-700 dark:text-indigo-300 overflow-x-auto bg-slate-50 dark:bg-slate-950 p-4 rounded border border-slate-100 dark:border-transparent">
[
  { "name": "Git Pull", "run": "git pull origin main" },
  { "name": "Install Dependencies", "run": "composer install --no-dev" },
  { "name": "Optimize Cache", "run": "php artisan config:cache" }
]</pre>
            </div>
        </div>
    </body>
    </html>
    <?php
}

function renderLiveStatus()
{
    global $statusFile;
    if (! file_exists($statusFile)) {
        header('Location: /health');
        exit();
    }
    $data = json_decode(file_get_contents($statusFile), true);
    $isFinished = isset($data['finished']) && $data['finished'] === true;
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
            <?php if (! $isFinished): ?>
            <meta http-equiv="refresh" content="2">
            <?php endif; ?>
        <script src="https://unpkg.com/@tailwindcss/browser@4"></script>
        <title>Execution Status</title>
        <?= renderHeadImports() ?>
    </head>
    <body class="bg-[#f8fafc] dark:bg-[#0b0f1a] text-slate-600 dark:text-slate-400 min-h-screen flex items-center justify-center font-mono p-6 transition-colors duration-200">
        <div class="w-full max-w-2xl p-8 bg-white dark:bg-[#161b2a] border border-slate-200 dark:border-slate-800 rounded-xl shadow-2xl">
            
            <div class="mb-8 flex justify-between items-end border-b border-slate-100 dark:border-slate-800 pb-6">
                <div>
                    <div class="text-[10px] text-slate-400 dark:text-slate-500 mb-1 uppercase tracking-[0.2em]">Current Progress</div>
                    <div class="text-xl text-slate-900 dark:text-white font-bold tracking-tight">
                        <?= $data['task'] ?>
                    </div>
                </div>
                <div class="text-right">
                    <span class="text-2xl font-black text-slate-200 dark:text-slate-800"><?= $data['index'] ?>/<?= $data['total'] ?></span>
                </div>
            </div>

            <div class="space-y-3 mb-8">
                <?php foreach ($data['history'] as $i => $item):
                    $status = $item['status'];
                    $name = $item['name'];

                    // Definición de estilos por estado
                    $badgeClass = match ($status) {
                        'success' => 'bg-emerald-500/10 text-emerald-500 border-emerald-500/20',
                        'failed' => 'bg-rose-500/10 text-rose-500 border-rose-500/20 animate-pulse',
                        'running' => 'bg-blue-500/10 text-blue-500 border-blue-500/40 border',
                        default => 'bg-slate-100 dark:bg-slate-800/50 text-slate-400 border-transparent',
                    };

                    $label = match ($status) {
                        'success' => 'DONE',
                        'failed' => 'FAIL',
                        'running' => 'BUSY',
                        default => 'WAIT',
                    };
                    ?>
                    <div class="flex items-center justify-between p-3 rounded-lg border <?= $status === 'running'
                        ? 'border-blue-500/20 bg-blue-500/5'
                        : 'border-transparent' ?> transition-all">
                        <div class="flex items-center gap-3">
                            <span class="text-[10px] px-2 py-0.5 rounded font-bold border uppercase <?= $badgeClass ?>">
                                <?= $label ?>
                            </span>
                            <span class="text-xs <?= $status === 'running'
                                ? 'text-slate-900 dark:text-white font-bold'
                                : ($status === 'pending' ? 'text-slate-500' : 'text-slate-700 dark:text-slate-400') ?>">
                                <?= $name ?>
                            </span>
                        </div>
                        <?php if ($status === 'running'): ?>
                            <div class="flex gap-1">
                                <span class="w-1 h-1 bg-blue-500 rounded-full animate-bounce"></span>
                                <span class="w-1 h-1 bg-blue-500 rounded-full animate-bounce [animation-delay:0.2s]"></span>
                                <span class="w-1 h-1 bg-blue-500 rounded-full animate-bounce [animation-delay:0.4s]"></span>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="relative pt-1">
                <div class="overflow-hidden h-1.5 mb-4 text-xs flex rounded bg-slate-100 dark:bg-slate-900">
                    <div style="width:<?= $data['running'] ?? true ? (($data['index'] - 1) / $data['total']) * 100 : 100 ?>%" class="shadow-none flex flex-col text-center whitespace-nowrap text-white justify-center bg-blue-600 transition-all duration-500"></div>
                </div>
            </div>
            
            <div class="flex justify-between items-center">
                <p class="text-[9px] text-slate-400 dark:text-slate-600 uppercase tracking-widest italic">
                    <?php if ($data['running'] ?? true): ?>
                        System executing instructions...
                    <?php else: ?>
                        Process halted. Check logs.
                    <?php endif; ?>
                </p>
                <a href="/health" class="text-[10px] text-blue-500 hover:underline">Exit Live View</a>
            </div>

            <?php if ($isFinished): ?>
                <div class="mb-6 p-4 rounded-lg border <?= $data['success']
                    ? 'bg-emerald-500/10 border-emerald-500/20 text-emerald-500'
                    : 'bg-rose-500/10 border-rose-500/20 text-rose-500' ?> flex justify-between items-center uppercase text-[10px] font-bold tracking-widest">
                    <span><?= $data['success'] ? '✓ Deployment Completed' : '✕ Deployment Failed' ?></span>
                    <span>Duration: <?= $data['duration'] ?>s</span>
                </div>
            <?php endif; ?>

            <div class="mt-8 flex justify-between items-center border-t border-slate-100 dark:border-slate-800 pt-6">
                <?php if ($isFinished): ?>
                    <div class="flex gap-4">
                        <a href="/health" class="bg-slate-800 text-white px-4 py-2 rounded text-[10px] font-bold hover:bg-slate-700 transition">BACK TO DASHBOARD</a>
                        <a href="/log/view?file=<?= urlencode($data['log_file']) ?>" class="border border-slate-200 dark:border-slate-800 px-4 py-2 rounded text-[10px] font-bold hover:bg-slate-50 dark:hover:bg-slate-800 transition">VIEW FULL LOG</a>
                    </div>
                <?php else: ?>
                    <p class="text-[9px] text-slate-400 italic animate-pulse tracking-widest">SYNCING WITH SERVER...</p>
                <?php endif; ?>
            </div>
        </div>
    </body>
    </html>
    <?php
}
