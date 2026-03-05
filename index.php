<?php
/**
 * Simple PHP Deployer
 * Minimalist Technical UI - PHP 8.5+
 * Format: JSON Instructions
 */

// 1. Load .env
if (file_exists(__DIR__ . '/.env')) {
    $lines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        [$name, $value] = explode('=', $line, 2);
        $_ENV[trim($name)] = trim($value);
    }
}

// Configuration with Defaults
$defaults = [
    'PROJECT_PATH' => [__DIR__, true],
    'INSTRUCTIONS_FILE' => ['deploy.json', true],
    'LOGS_PATH' => ['./logs', true],
    'WEBHOOK_METHOD' => ['POST', true],
];

$config = [
    'project_path'   => $_ENV['PROJECT_PATH'] ?? $defaults['PROJECT_PATH'][0],
    'instructions'   => $_ENV['INSTRUCTIONS_FILE'] ?? $defaults['INSTRUCTIONS_FILE'][0],
    'logs_path'      => $_ENV['LOGS_PATH'] ?? $defaults['LOGS_PATH'][0],
    'bot_token'      => $_ENV['TELEGRAM_BOT_TOKEN'] ?? '',
    'chat_id'        => $_ENV['TELEGRAM_CHAT_ID'] ?? '',
    'thread_id'      => $_ENV['TELEGRAM_THREAD_ID'] ?? null,
    'secure_token'   => $_ENV['SECURITY_TOKEN'] ?? '',
    'webhook_method' => $_ENV['WEBHOOK_METHOD'] ?? $defaults['WEBHOOK_METHOD'][0],
];

// Ensure logs directory exists
if (!is_dir($config['logs_path'])) {
    mkdir($config['logs_path'], 0777, true);
}

$statusFile = $config['logs_path'] . '/.current_status';


// 2. Router
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

switch ($uri) {
    case '/':
    case '/health':
        renderHealthView();
        break;
    case '/webhook/deploy':
        validateSecurity();
        $manual = isset($_GET['manual']) && $_GET['manual'] == '1';
        if (
            $method !== $config['webhook_method'] 
                && !(
                    $manual 
                        && $method === 'GET'
                )
        ) {
            http_response_code(405);
            exit('Method Not Allowed');
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
        $res = sendTelegram("Simple PHP Deployer: Test Notification");
        header('Location: /health?notified=' . ($res ? '1' : '0'));
        break;
    case '/clear-history':
        clearHistory();
        break;
}

// --- LOGIC ---

function validateSecurity() {
    global $config;
    if (empty($config['secure_token'])) return;
    
    // Allow manual access from UI
    if (isset($_GET['manual']) && $_GET['manual'] === '1') return;

    $headers = getallheaders();
    $token = $headers['X-Deploy-Token'] ?? $_GET['token'] ?? '';
    if ($token !== $config['secure_token']) {
        http_response_code(401);
        exit('Unauthorized');
    }
}

function executeDeployment() {
    global $config, $statusFile;
    
    if (file_exists($statusFile)) {
        http_response_code(409);
        exit('Deployment already in progress.');
    }

    $startTime = microtime(true);
    $logFilename = 'deploy_' . date('Ymd_His') . '.log';
    $logPath = $config['logs_path'] . '/' . $logFilename;
    
    if (!file_exists($config['instructions'])) {
        exit('Instruction file missing.');
    }

    $jsonContent = file_get_contents($config['instructions']);
    $tasks = json_decode($jsonContent, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        exit('Invalid JSON in instructions file.');
    }
    
    // Initial Lock
    file_put_contents($statusFile, json_encode(['running' => true, 'task' => 'Starting...', 'index' => 0, 'total' => count($tasks), 'start' => $startTime]));

    chdir($config['project_path']);
    $success = true;
    $failedTask = "";
    $fullLog = "START: " . date('Y-m-d H:i:s') . "\n";

    foreach ($tasks as $index => $task) {
        $name = $task['name'] ?? 'Unnamed Task';
        $cmd = $task['run'] ?? '';
        
        file_put_contents($statusFile, json_encode([
            'running' => true,
            'task' => $name,
            'index' => $index + 1,
            'total' => count($tasks),
            'start' => $startTime
        ]));

        $fullLog .= "\n[TASK]: $name\n[CMD]: $cmd\n";
        
        $process = proc_open($cmd, [1 => ["pipe", "w"], 2 => ["pipe", "w"]], $pipes);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        $exitCode = proc_close($process);

        $fullLog .= "STDOUT: $stdout\nSTDERR: $stderr\nEXIT: $exitCode\n";

        if ($exitCode !== 0) {
            $success = false;
            $failedTask = $name;
            break;
        }
    }

    $duration = round(microtime(true) - $startTime, 2);
    file_put_contents($logPath, $fullLog . "\nEND. Duration: {$duration}s");
    unlink($statusFile);

    $protocol = isset($_SERVER['HTTPS']) ? 'https://' : 'http://';
    $logUrl = $protocol . $_SERVER['HTTP_HOST'] . "/log/view?file=" . urlencode($logFilename);
    
    $statusText = $success ? "SUCCESS" : "FAILED at $failedTask";
    sendTelegram("Simple PHP Deployer Report\nStatus: $statusText\nDuration: {$duration}s\nLink: $logUrl");
    
    if (isset($_GET['manual'])) {
        header('Location: /health?deployed=' . ($success ? '1' : '0'));
    } else {
        echo "Done.";
    }
}

function sendTelegram($text) {
    global $config;
    if (empty($config['bot_token'])) return false;
    $ch = curl_init("https://api.telegram.org/bot{$config['bot_token']}/sendMessage");
    curl_setopt($ch, CURLOPT_POSTFIELDS, [
        'chat_id' => $config['chat_id'],
        'text' => $text,
        'message_thread_id' => $config['thread_id']
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    return curl_exec($ch);
}

function showSpecificLog($file) {
    global $config;
    $path = realpath($config['logs_path'] . '/' . $file);
    if ($path && str_starts_with($path, realpath($config['logs_path']))) {
        header('Content-Type: text/plain');
        readfile($path);
    } else exit('Access Denied');
}

function showLastLog() {
    global $config;
    $logs = glob($config['logs_path'] . '/*.log');
    if (!$logs) exit('No logs available.');
    usort($logs, fn($a, $b) => filemtime($b) - filemtime($a));
    header('Content-Type: text/plain');
    readfile($logs[0]);
}

function clearHistory() {
    global $config;
    $logs = glob($config['logs_path'] . '/*.log');
    foreach ($logs as $log) unlink($log);
    header('Location: /health?cleared=1');
}

// --- VIEWS ---

function renderHealthView() {
    global $config, $statusFile, $defaults;
    $serverIp = $_SERVER['SERVER_ADDR'] ?? 'Local';
    $serverDomain = $_SERVER['HTTP_HOST'] ?? 'Unknown Domain';
    $phpVersion = PHP_VERSION;
    $instructionExists = file_exists($config['instructions']);
    $logs = glob($config['logs_path'] . '/*.log');
    usort($logs, fn($a, $b) => filemtime($b) - filemtime($a));
    $lastLogs = array_slice($logs, 0, 5);
    $isDeploying = file_exists($statusFile);
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <script src="https://unpkg.com/@tailwindcss/browser@4"></script>
        <title>System Health - Deployer</title>
    </head>
    <body class="bg-[#0b0f1a] text-slate-300 p-8 font-mono text-sm">
        <div class="max-w-6xl mx-auto">
            <div class="flex justify-between items-start mb-10">
                <div>
                    <h1 class="text-white font-bold text-xl tracking-tighter uppercase">SIMPLE PHP <span class="text-[#a855f7]">DEPLOYER</span></h1>
                    <p class="text-slate-500">
                        Host: <span class="text-slate-400"><?= $serverDomain ?></span> | 
                        IP: <span class="text-slate-400"><?= $serverIp ?></span> | 
                        PHP: <span class="text-slate-400"><?= $phpVersion ?></span>
                    </p>
                </div>
                <?php if ($isDeploying): ?>
                    <a href="/status/live" class="bg-blue-600 text-white px-3 py-1 text-xs font-bold rounded shadow-lg shadow-blue-500/20 animate-pulse">PROCESS RUNNING</a>
                <?php endif; ?>
            </div>

            <?php if (!$instructionExists): ?>
                <div class="bg-amber-950/30 border border-amber-500/50 p-4 rounded mb-8 text-amber-200">
                    WARNING: Instruction file not found at "<?= $config['instructions'] ?>".
                </div>
            <?php endif; ?>

            <div class="grid grid-cols-1 lg:grid-cols-4 gap-6">
                <div class="lg:col-span-1 space-y-4">
                    <div class="bg-[#161b2a] border border-slate-800 p-5 rounded-lg shadow-xl text-[10px]">
                        <h2 class="text-slate-500 font-bold mb-4 border-b border-slate-800 pb-2 uppercase tracking-widest">System Config</h2>
                        <div class="space-y-4">
                            <?php 
                            $vars = [
                                'Path' => 'project_path',
                                'Instructions' => 'instructions',
                                'Logs' => 'logs_path',
                                'Token' => 'bot_token',
                                'Security' => 'secure_token'
                            ];
                            foreach($vars as $label => $key): 
                                $isSet = !empty($config[$key]);
                                $isDefault = isset($defaults[strtoupper($key)]) && $config[$key] === $defaults[strtoupper($key)][0];
                            ?>
                                <div class="flex flex-row items-center justify-between gap-4">
                                    <div class="flex items-center gap-1 min-w-0">
                                        <span class="text-slate-500 uppercase truncate"><?= $label ?></span>
                                        <?php if ($isDefault): ?>
                                            <span class="text-slate-600 font-bold tracking-tighter shrink-0">(DEFAULT)</span>
                                        <?php endif; ?>
                                    </div>
                                    <span class="px-2 py-0.5 rounded font-bold shrink-0 <?= $isSet ? 'bg-emerald-500/10 text-emerald-500' : 'bg-rose-500/10 text-rose-500' ?>">
                                        <?= $isSet ? 'CONFIGURED' : 'UNDEFINED' ?>
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="bg-[#161b2a] border border-slate-800 p-5 rounded-lg shadow-xl">
                        <h2 class="text-slate-500 text-[10px] font-bold mb-4 border-b border-slate-800 pb-2 uppercase tracking-widest">Quick Actions</h2>
                        <div class="space-y-2">
                            <?php if ($isDeploying): ?>
                                <button disabled class="block w-full text-center bg-slate-800 text-slate-600 py-2 rounded text-xs font-bold cursor-not-allowed">DEPLOY IN PROGRESS</button>
                            <?php else: ?>
                                <a href="/webhook/deploy?manual=1" class="block w-full text-center bg-slate-800 hover:bg-slate-700 py-2 rounded text-xs font-bold transition">MANUAL DEPLOY</a>
                            <?php endif; ?>
                            <a href="/test-notify" class="block w-full text-center border border-slate-800 hover:bg-slate-800 py-2 rounded text-xs transition">TEST NOTIFICATION</a>
                            <a href="/clear-history" onclick="return confirm('Clear all logs?')" class="block w-full text-center text-rose-500/70 hover:text-rose-500 py-2 text-xs transition">CLEAR HISTORY</a>
                        </div>
                    </div>
                </div>

                <div class="lg:col-span-3 bg-[#161b2a] border border-slate-800 rounded-lg overflow-hidden shadow-xl flex flex-col">
                    <div class="p-5 border-b border-slate-800 flex justify-between items-center bg-slate-900/30">
                        <h2 class="text-slate-500 text-[10px] font-bold uppercase tracking-widest">Recent Execution History</h2>
                        <a href="/log/last" class="text-[10px] text-indigo-400 hover:text-indigo-300 font-bold uppercase tracking-tighter transition">View Latest Raw</a>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left border-collapse">
                            <thead>
                                <tr class="bg-slate-900/50 text-slate-500 text-[10px] uppercase">
                                    <th class="px-6 py-3 font-bold border-b border-slate-800">Log Identifier</th>
                                    <th class="px-6 py-3 font-bold border-b border-slate-800">Timestamp</th>
                                    <th class="px-6 py-3 font-bold text-right border-b border-slate-800">Reference</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-800">
                                <?php foreach ($lastLogs as $logPath): $fn = basename($logPath); ?>
                                    <tr class="hover:bg-slate-800/20 transition">
                                        <td class="px-6 py-4 text-slate-400 font-mono text-xs"><?= $fn ?></td>
                                        <td class="px-6 py-4 text-slate-600 text-xs"><?= date('Y-m-d H:i:s', filemtime($logPath)) ?></td>
                                        <td class="px-6 py-4 text-right">
                                            <a href="/log/view?file=<?= urlencode($fn) ?>" class="text-blue-500 hover:text-blue-400 font-bold text-xs transition">OPEN</a>
                                        </td>
                                    </tr>
                                <?php endforeach; if(!$lastLogs): ?>
                                    <tr><td colspan="3" class="p-10 text-center text-slate-600 italic">No execution history found.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            
            <div class="mt-8 bg-[#161b2a] border border-slate-800 p-6 rounded-lg shadow-xl">
                <h2 class="text-slate-500 text-[10px] font-bold mb-4 border-b border-slate-800 pb-2 uppercase tracking-widest">Example deploy.json</h2>
                <pre class="text-[11px] text-indigo-300 overflow-x-auto bg-slate-950 p-4 rounded">
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

function renderLiveStatus() {
    global $statusFile;
    if (!file_exists($statusFile)) { header('Location: /health'); exit; }
    $data = json_decode(file_get_contents($statusFile), true);
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta http-equiv="refresh" content="2">
        <script src="https://unpkg.com/@tailwindcss/browser@4"></script>
        <title>Execution Status</title>
    </head>
    <body class="bg-[#0b0f1a] text-slate-400 min-h-screen flex items-center justify-center font-mono p-6">
        <div class="w-full max-w-xl p-10 bg-[#161b2a] border border-slate-800 rounded-lg shadow-2xl">
            <div class="mb-8 text-center lg:text-left">
                <div class="text-[10px] text-slate-500 mb-2 uppercase tracking-widest flex justify-between">
                    <span>Task <?= $data['index'] ?> / <?= $data['total'] ?></span>
                    <span class="animate-pulse text-blue-400">● Executing</span>
                </div>
                <div class="text-lg text-white font-bold tracking-tight"><?= $data['task'] ?></div>
            </div>
            <div class="w-full bg-slate-950 h-1.5 mb-4 rounded-full overflow-hidden">
                <div class="bg-blue-600 h-full transition-all duration-700" style="width: <?= ($data['total'] > 0) ? ($data['index'] / $data['total']) * 100 : 0 ?>%"></div>
            </div>
            <p class="text-[10px] text-slate-600 italic">Capturing STDOUT/STDERR to log file... Auto-refreshing.</p>
        </div>
    </body>
    </html>
    <?php
}
