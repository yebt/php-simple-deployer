<?php
/**
 * Raccoon Soft - Live Deployer
 * Tailwind CSS 4 + SSE Live Streaming
 */

// 1. Load .env
if (file_exists(__DIR__ . '/.env')) {
    $lines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (str_contains($line, '=') && strpos(trim($line), '#') !== 0) {
            list($name, $value) = explode('=', $line, 2);
            $_ENV[trim($name)] = trim($value);
        }
    }
}

$config = [
    'project_path'   => $_ENV['PROJECT_PATH'] ?? '',
    'instructions'   => $_ENV['INSTRUCTIONS_FILE'] ?? 'deploy.yml',
    'logs_path'      => $_ENV['LOGS_PATH'] ?? './logs',
    'bot_token'      => $_ENV['TELEGRAM_BOT_TOKEN'] ?? '',
    'chat_id'        => $_ENV['TELEGRAM_CHAT_ID'] ?? '',
    'thread_id'      => $_ENV['TELEGRAM_THREAD_ID'] ?? null,
    'secure_token'   => $_ENV['SECURITY_TOKEN'] ?? '',
    'webhook_method' => $_ENV['WEBHOOK_METHOD'] ?? 'POST',
];

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];
$currentLogFile = $config['logs_path'] . '/.current_execution';

// --- ROUTER ---

if ($uri === '/' || $uri === '/health') {
    renderHealthView();
} 
elseif ($uri === '/webhook/deploy') {
    validateSecurity();
    executeDeployment();
}
elseif ($uri === '/log/stream') {
    handleStream();
}
elseif ($uri === '/log/last') {
    showLastLog();
}
elseif ($uri === '/test-notify') {
    sendTelegram("Raccoon Soft: Test Notification 🚀");
    header('Location: /health?notified=1');
}

// --- LOGIC ---

function validateSecurity() {
    global $config;
    if (empty($config['secure_token'])) return;
    $token = getallheaders()['X-Deploy-Token'] ?? $_GET['token'] ?? '';
    if ($token !== $config['secure_token']) {
        http_response_code(401);
        exit('Unauthorized');
    }
}

function executeDeployment() {
    global $config, $currentLogFile;
    $startTime = microtime(true);
    if (!is_dir($config['logs_path'])) mkdir($config['logs_path'], 0777, true);
    
    // Clear previous live log
    file_put_contents($currentLogFile, "INIT: Starting deployment...\n");

    if (!file_exists($config['instructions'])) {
        file_put_contents($currentLogFile, "ERROR: Instruction file missing.\n");
        exit;
    }

    $yaml = file_get_contents($config['instructions']);
    preg_match_all('/-\s*name:\s*(.+)\n\s+run:\s*(.+)/', $yaml, $matches, PREG_SET_ORDER);
    
    chdir($config['project_path']);
    $success = true;
    $outputAccumulator = "";

    foreach ($matches as $task) {
        $name = trim($task[1]);
        $cmd = trim($task[2]);
        $taskHeader = "\n>> [TASK]: $name\n";
        file_put_contents($currentLogFile, $taskHeader, FILE_APPEND);
        $outputAccumulator .= $taskHeader;

        $process = proc_open($cmd, [1 => ["pipe", "w"], 2 => ["pipe", "w"]], $pipes);
        
        while ($line = fgets($pipes[1])) {
            file_put_contents($currentLogFile, $line, FILE_APPEND);
            $outputAccumulator .= $line;
        }
        $stderr = stream_get_contents($pipes[2]);
        if ($stderr) {
            file_put_contents($currentLogFile, "[ERR] " . $stderr, FILE_APPEND);
            $outputAccumulator .= $stderr;
        }
        
        $exitCode = proc_close($process);
        if ($exitCode !== 0) {
            $success = false;
            break;
        }
    }

    $duration = round(microtime(true) - $startTime, 2);
    $finalStatus = $success ? "SUCCESS" : "FAILED";
    file_put_contents($currentLogFile, "\n--- FINISHED: $finalStatus ---", FILE_APPEND);

    // Save permanent log
    $logFile = $config['logs_path'] . '/deploy_' . date('Ymd_His') . '.log';
    file_put_contents($logFile, $outputAccumulator);

    // Telegram
    $logUrl = (isset($_SERVER['HTTPS']) ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . "/log/last";
    $msg = "🚀 *Deploy: " . ($success ? "OK" : "ERROR") . "*\nDur: {$duration}s\n[View Log]($logUrl)";
    sendTelegram($msg);
}

function handleStream() {
    global $currentLogFile;
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('Connection: keep-alive');

    $lastPos = 0;
    while (true) {
        clearstatcache();
        if (file_exists($currentLogFile)) {
            $content = file_get_contents($currentLogFile);
            $newContent = substr($content, $lastPos);
            if ($newContent) {
                echo "data: " . nl2br(htmlspecialchars($newContent)) . "\n\n";
                $lastPos = strlen($content);
            }
            if (str_contains($content, '--- FINISHED')) break;
        }
        ob_flush(); flush();
        sleep(1);
    }
}

function sendTelegram($text) {
    global $config;
    if (empty($config['bot_token'])) return false;
    $data = [
        'chat_id' => $config['chat_id'],
        'text' => $text,
        'parse_mode' => 'Markdown',
        'message_thread_id' => $config['thread_id']
    ];
    $ch = curl_init("https://api.telegram.org/bot{$config['bot_token']}/sendMessage");
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_exec($ch);
    curl_close($ch);
    return true;
}

function showLastLog() {
    global $config;
    $files = glob($config['logs_path'] . '/*.log');
    if (!$files) exit('No logs.');
    usort($files, fn($a, $b) => filemtime($b) - filemtime($a));
    header('Content-Type: text/plain');
    readfile($files[0]);
}

function renderHealthView() {
    $serverIp = $_SERVER['SERVER_ADDR'] ?? 'Unknown';
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <script src="https://unpkg.com/@tailwindcss/browser@4"></script>
        <title>Raccoon Deployer</title>
    </head>
    <body class="bg-[#0f172a] text-slate-200 p-8">
        <div class="max-w-5xl mx-auto">
            <div class="flex justify-between items-end mb-8">
                <div>
                    <h1 class="text-2xl font-bold text-white">Raccoon <span class="text-blue-500">Deployer</span></h1>
                    <p class="text-slate-400 text-sm">Server: <?= $_SERVER['SERVER_NAME'] ?> (<?= $serverIp ?>)</p>
                </div>
                <a href="/test-notify" class="bg-blue-600 px-4 py-2 rounded-lg text-sm font-bold hover:bg-blue-500 transition">Test Telegram</a>
            </div>

            <div class="grid grid-cols-1 gap-6">
                <div class="bg-[#1e293b] rounded-2xl border border-slate-700 overflow-hidden shadow-2xl">
                    <div class="bg-slate-800 px-4 py-2 border-b border-slate-700 flex justify-between items-center">
                        <span class="text-xs font-mono text-slate-400">Live Status Stream</span>
                        <div id="status-dot" class="w-2 h-2 rounded-full bg-slate-500"></div>
                    </div>
                    <div id="console" class="p-6 h-96 overflow-y-auto font-mono text-sm text-blue-300 space-y-1">
                        Waiting for deployment trigger...
                    </div>
                </div>
            </div>
        </div>

        <script>
            const consoleBox = document.getElementById('console');
            const dot = document.getElementById('status-dot');
            
            function startStream() {
                const eventSource = new EventSource('/log/stream');
                consoleBox.innerHTML = '<p class="text-yellow-400">> Stream connected. Listening for events...</p>';
                dot.classList.replace('bg-slate-500', 'bg-green-500');

                eventSource.onmessage = (event) => {
                    const p = document.createElement('p');
                    p.innerHTML = event.data;
                    consoleBox.appendChild(p);
                    consoleBox.scrollTop = consoleBox.scrollHeight;
                    
                    if (event.data.includes('FINISHED')) {
                        dot.classList.replace('bg-green-500', 'bg-blue-500');
                        eventSource.close();
                    }
                };

                eventSource.onerror = () => {
                    // We don't close on error to allow auto-reconnect if the script is idle
                };
            }

            // Auto-start stream to catch any ongoing or new deploy
            startStream();
        </script>
    </body>
    </html>
    <?php
}
