<?php

/**
 * Simple PHP Deployer
 * Minimalist Technical UI - PHP 8.5+
 * Format: JSON Instructions
 */

function dump_highlight($variable)
{
    // Convertimos la variable a una cadena representativa
    $output = "<?php\n\n\$var = ".var_export($variable, true).";\n";

    // highlight_string colorea el código y lo imprime directamente
    highlight_string($output);
}

// INITS
// ================================================================================

// 1. Load .env
if (file_exists(__DIR__.'/.env')) {
    $lines = file(__DIR__.'/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0 || strpos($line, '=') === false)
            continue;
        [$name, $value] = explode('=', $line, 2);
        $_ENV[trim($name)] = trim($value ?? '');
    }
}

function env($key, $default = null)
{
    $value = $_ENV[$key] ?? $default;
    if (is_string($value)) {
        $lower = strtolower($value);
        if ($lower === 'true')
            return true;
        if ($lower === 'false')
            return false;
        if ($lower === 'null')
            return null;
    }

    return $value;
}

// LOAD AUTH
// ================================================================================

$user = env('LOAD_USER') ?? null;
$pass = env('LOAD_PASS') ?? null;
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($user && $pass && ($method == 'GET' || $method == 'POST')) {
    if (! isset($_SERVER['PHP_AUTH_USER']) || $_SERVER['PHP_AUTH_USER'] != $user || $_SERVER['PHP_AUTH_PW'] != $pass) {
        header('WWW-Authenticate: Basic realm="Acceso restringido"');
        header('HTTP/1.0 401 Unauthorized');
        echo 'Acceso denegado';
        exit();
    }
}

// CONFIGS
// ================================================================================

// Configuration with Defaults
$defaults = [
    // 'PROJECT_PATH' => [__DIR__, true],
    'INSTRUCTIONS_FILE' => ['deploy.json', true],
    'LOGS_PATH' => ['./logs', true],
    'WEBHOOK_METHOD' => ['POST', true],
];

$config = [
    // 'project_path' => env('PROJECT_PATH') ?? $defaults['PROJECT_PATH'][0],
    'project_path' => env('PROJECT_PATH') ?? null,
    'instructions' => env('INSTRUCTIONS_FILE') ?? $defaults['INSTRUCTIONS_FILE'][0],
    'logs_path' => env('LOGS_PATH') ?? $defaults['LOGS_PATH'][0],
    'telegram_enabled' => env('TELEGRAM_NOTIFICATIONS') ?? true,
    'bot_token' => env('TELEGRAM_BOT_TOKEN') ?? '',
    'chat_id' => env('TELEGRAM_CHAT_ID') ?? '',
    'thread_id' => env('TELEGRAM_THREAD_ID') ?? null,
    'secure_token' => env('SECURITY_TOKEN') ?? '',
    'webhook_method' => env('WEBHOOK_METHOD') ?? $defaults['WEBHOOK_METHOD'][0],
];

// Ensure logs directory exists
if (! is_dir($config['logs_path'])) {
    mkdir($config['logs_path'], 0777, true);
}

// Ensure paths with realpaths
foreach (['project_path', 'logs_path'] as $key) {
    if (isset($config[$key]))
        $config[$key] = realpath($config[$key]);
}

$statusFile = $config['logs_path'].'/.current_status';

// CLI CALL
// ================================================================================

// Execute the deployment if called from CLI with the specific argument
if (isset($argv[1]) && $argv[1] === 'run-deploy') {
    define('CLI_HOST', $argv[2] ?? 'localhost');
    executeDeploymentWithSingleShellProccess();
    exit();
}

// ROUTER
// ================================================================================
class RegExpRouter
{
    private $routes = [];

    public function add($pattern, $callback)
    {
        // Envolvemos el patrón en delimitadores para PHP
        $this->routes['#^'.$pattern.'$#'] = $callback;
    }

    public function resolve($url)
    {
        $path = parse_url($url, PHP_URL_PATH);

        foreach ($this->routes as $pattern => $callback) {
            // if (preg_match($pattern, $url, $matches)) {
            if (preg_match($pattern, $path, $matches)) {
                // remove the full match from the beginning of the array
                array_shift($matches);

                return call_user_func_array($callback, $matches);
            }
        }

        // $notFoundCallback = $this->routes['#^/404$#'] ?? function(){
        $notFoundCallback = $this->routes['#^404$#'] ?? function () {
            header('HTTP/1.0 404 Not Found');
            echo '404 Not Found';
        };

        return call_user_func($notFoundCallback);
    }
}

$router = new RegExpRouter;

// ROUTES
// $router->add('/usuario/(\d+)/perfil', function($id) {
//     echo "Mostrando perfil del usuario con ID: " . $id;
// });

$router->add('/status/check', 'actionStatusCheck');
$router->add('/status/data', 'actionStatusData');
$router->add('/', 'actionHome');
$router->add('/health', 'actionHealthView');

if (env('MODE', 'production') !== 'production') {
    $router->add('/debugdeploy', 'actionDebugDeploy');
}
$router->add('/webhook/deploy', 'actionWebhookDeploy');
$router->add('/webhook/deploy/nowait', 'actionWebhookDeployNoWait');

$router->add('/log/view', 'actionLogView');
$router->add('/log/rview/([a-zA-Z0-9_]+)', 'actionLogRawView');
$router->add('/log/bview/([a-zA-Z0-9_]+)', 'actionLogBaseRawView');
$router->add('/log/htmlview/([a-zA-Z0-9_]+)', 'actionLogHtmlView');
$router->add('/log/last', 'actionLogLast');
// $router->add('/latest', 'actionLogLatestHtml');
$router->add('/log/lasthtml', 'actionLogLatestHtml');
$router->add('/status/live', 'actionStatusLive');
$router->add('/deploy/stop', 'actionDeployStop');
$router->add('/test-notify', 'actionNotifyTest');
$router->add('/clear-history', 'actionClearHistory');

$router->add('404', function () {
    header('HTTP/1.0 404 Not Found');
    echo '404 Route Not Found';
});

// 2. Router
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

$router->resolve($uri);

// --- ACTIONS ---
// ================================================================================

function actionStatusCheck()
{
    global $statusFile;

    header('Content-Type: application/json');
    if (! file_exists($statusFile)) {
        echo json_encode(['finished' => true]);
    } else {
        $data = json_decode(file_get_contents($statusFile), true);
        echo json_encode(['finished' => isset($data['finished']) && $data['finished'] === true]);
    }
    exit();
}

function actionStatusData()
{
    global $statusFile;
    header('Content-Type: application/json');
    if (! file_exists($statusFile)) {
        echo json_encode(['finished' => true]);
    } else {
        echo file_get_contents($statusFile);
    }
    exit();
}

function actionHome()
{
    header('Location: /health');
    exit();
}

function actionHealthView()
{
    renderHealthView();
}

function actionWebhookDeploy()
{
    global $config, $method;
    validateSecurity();
    $manual = isset($_GET['manual']) && $_GET['manual'] == '1';
    if ($method !== $config['webhook_method'] && ! ($manual && $method === 'GET')) {
        http_response_code(405);
        exit('Method Not Allowed');
    }

    if (isset($_GET['manual']) && $_GET['manual'] == '1') {
        // Validate configuration before executing
        $validationError = validateDeploymentConfig();
        if ($validationError) {
            renderValidationError($validationError);
            exit();
        }

        // Ejecutamos el script de despliegue en segundo plano
        // exec('php '.__FILE__.' run-deploy > /dev/null 2>&1 &');

        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        exec('php '.__FILE__.' run-deploy '.escapeshellarg($host).' > /dev/null 2>&1 &');
        // wait lock file
        usleep(500000);
        header('Location: /health');
        exit();
    }

    executeDeploymentWithSingleShellProccess();
}

function actionDebugDeploy()
{
    executeDeploymentWithSingleShellProccess();
}

function actionWebhookDeployNoWait()
{
    global $config, $method;
    validateSecurity();
    if ($method !== $config['webhook_method']) {
        http_response_code(405);
        exit('Method Not Allowed');
    }

    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    exec('php '.__FILE__.' run-deploy '.escapeshellarg($host).' > /dev/null 2>&1 &');

    // set header json
    http_response_code(202);
    header('Content-Type: application/json');
    echo
        json_encode([
            'status' => 'accepted',
            'message' => 'Deployment initiated in background',
        ])
    ;
}

function actionLogView()
{
    showSpecificLog($_GET['file'] ?? '');
}

function actionLogBaseRawView($id)
{
    $file = $id.'.log.rlog';
    showSpecificLog($file);
}

function actionLogHtmlView($id)
{
    $file = $id.'.log.html';
    showSpecificLog($file, 'text/html');
}

function actionLogRawView($id)
{
    $file = $id.'.log';
    showSpecificLog($file);
}

function actionLogLast()
{
    showLastLog();
}

function actionLogLatestHtml()
{
    showLastLogHtml();
}

function actionStatusLive()
{
    renderLiveStatus();
}

function actionNotifyTest()
{
    $server = $_SERVER['HTTP_HOST'] ?? 'localhost';
    // date in UTC-5  America/Bogota timezone
    $date = new DateTime('now', new DateTimeZone('America/Bogota'));
    $formattedDate = prugueStrData($date->format('Y-m-d H:i:s'));
    $protocol = isset($_SERVER['HTTPS']) ? 'https://' : 'http://';
    $dashboardUrl = "$protocol{$server}/health";

    // @mago-format-ignore-next
    $res = sendTelegram(
    <<<MARKDOWN
🧪 *System Check: SPHPD*

*Host:* `$server`
*Status:* `Operational`
*Timestamp:* _{$formattedDate}_

[Ver Dashboard]($dashboardUrl)
MARKDOWN
);
    header('Location: /health?notified='.($res ? '1' : '0'));
}

function actionClearHistory()
{
    clearHistory();
}

function actionDeployStop()
{
    global $statusFile;
    
    // Security check
    validateSecurity();
    
    // Check if there's a deployment running
    if (!file_exists($statusFile)) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'No deployment in progress']);
        exit();
    }
    
    $statusData = json_decode(file_get_contents($statusFile), true);
    
    // Check if it's actually running
    if (!isset($statusData['running']) || !$statusData['running']) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'No deployment running']);
        exit();
    }
    
    // Kill all PHP processes that are running deployment
    // This will catch the shell process (stdbuf -o0 -e0 bash)
    $cmd = "pkill -f 'stdbuf -o0 -e0 bash' || pkill -P $$ 2>/dev/null";
    shell_exec($cmd);
    
    // Update status to stopped
    $statusData['running'] = false;
    $statusData['finished'] = true;
    $statusData['stopped_at'] = date('Y-m-d H:i:s');
    $statusData['task'] = 'DEPLOYMENT STOPPED BY USER';
    
    file_put_contents($statusFile, json_encode($statusData));
    
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'message' => 'Deployment stopped']);
    exit();
}

// --- LOGIC ---
// ================================================================================

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

function validateInstructions($tasks)
{
    if (! is_array($tasks)) {
        return 'Invalid instructions format: expected an array of tasks.';
    }

    foreach ($tasks as $index => $task) {
        if (! is_array($task)) {
            return 'Invalid Instructions format';
        }

        if (! isset($task['run']) || empty($task['run']) && $task['run'] !== '0' && $task['run'] !== 0) {
            $name = $task['name'] ?? 'Task #'.($index + 1);

            return "Task '{$name}' is missing a 'run' command.";
        }

        if (! is_string($task['run']) && ! is_array($task['run'])) {
            $name = $task['name'] ?? 'Task #'.($index + 1);

            return "Task '{$name}' 'run' must be a string or an array.";
        }
    }

    return true;
}

function convertYmlToJson($ymlPath)
{
    $yqPath = env('YQ_PATH');

    // Execute yq to convert YML to JSON with error output
    $cmd = escapeshellcmd("$yqPath -o=json '$ymlPath' 2>&1");
    $output = shell_exec($cmd);

    if ($output === null || $output === false) {
        return null;
    }

    $trimmed = trim($output);
    
    // Check if there's an error in the output
    if (stripos($trimmed, 'error') !== false || stripos($trimmed, 'failed') !== false) {
        return null;
    }

    // Validate that output is valid JSON
    json_decode($trimmed, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return null;
    }

    return $trimmed;
}

function validateYqlPathForYml()
{
    global $config;
    $instructionsFile = $config['instructions'];
    
    // Check if it's a YML file
    if (strtolower(pathinfo($instructionsFile, PATHINFO_EXTENSION)) === 'yml' 
        || strtolower(pathinfo($instructionsFile, PATHINFO_EXTENSION)) === 'yaml') {
        $yqPath = env('YQ_PATH');
        if (! $yqPath) {
            return 'YQ_PATH environment variable is not set. Required for processing YAML files.';
        }
        
        // Check if yq binary exists
        if (!file_exists($yqPath)) {
            return "YQ binary not found at path: $yqPath";
        }
        
        // Check if it's a regular file
        if (!is_file($yqPath)) {
            return "YQ path is not a regular file: $yqPath";
        }
        
        // Check if it's executable, if not try to fix it
        if (!is_executable($yqPath)) {
            // Try to make it executable
            if (@chmod($yqPath, 0755)) {
                echo "[INFO] YQ binary permissions fixed: chmod +x applied to $yqPath\n";
            } else {
                return "YQ binary is not executable and cannot fix permissions. Run: chmod +x $yqPath";
            }
        }
        
        // Verify yq works by running version command
        $testCmd = escapeshellcmd("$yqPath --version");
        $testOutput = shell_exec($testCmd.' 2>&1');
        if ($testOutput === null || $testOutput === false || stripos($testOutput, 'yq') === false) {
            return "YQ binary not working at path: $yqPath";
        }
    }
    return null;
}

function validateDeploymentConfig()
{
    global $config;

    // Validate required config variables
    $requiredVars = [
        'project_path' => $config['project_path'],
        'instructions' => $config['instructions'],
    ];
    foreach ($requiredVars as $label => $value) {
        if (empty($value) || ! file_exists($value) && $label === 'project_path') {
            return "Error: Invalid or missing configuration: $label";
        }
    }

    // Validate instructions file exists
    if (! file_exists($config['instructions'])) {
        return 'Instruction file not found at: '.$config['instructions'];
    }

    // Validate YQL path if YML is used
    $yqlError = validateYqlPathForYml();
    if ($yqlError) {
        return $yqlError;
    }

    // Validate instructions content
    $jsonContent = getInstructionsContent();
    if ($jsonContent === null || $jsonContent === false) {
        $instructionsFile = $config['instructions'];
        $ext = strtolower(pathinfo($instructionsFile, PATHINFO_EXTENSION));
        if ($ext === 'yml' || $ext === 'yaml') {
            return 'Failed to convert YAML to JSON. Check YQL binary or YAML syntax.';
        }
        return 'Failed to read or convert instructions file.';
    }

    $tasks = json_decode($jsonContent, true);
    if (is_null($tasks)) {
        $jsonError = json_last_error_msg();
        return "Invalid JSON format in instructions file: $jsonError";
    }

    $errInstructions = validateInstructions($tasks);
    if ($errInstructions !== true) {
        return "Invalid task instructions: $errInstructions";
    }

    if (json_last_error() !== JSON_ERROR_NONE) {
        return 'JSON error in instructions file: '.json_last_error_msg();
    }

    return null;
}

function getInstructionsContent()
{
    global $config;
    $instructionsFile = $config['instructions'];

    // Check if it's a YML file
    if (
        strtolower(pathinfo($instructionsFile, PATHINFO_EXTENSION)) === 'yml'
        || strtolower(pathinfo($instructionsFile, PATHINFO_EXTENSION)) === 'yaml'
    ) {
        return convertYmlToJson($instructionsFile);
    }

    // Otherwise, read as JSON
    return file_get_contents($instructionsFile);
}

function validateJsonContent()
{
    global $config;
    $jsonContent = getInstructionsContent();

    if ($jsonContent === null || $jsonContent === false) {
        return [
            'Deployment started. Processing instructions...',
            'Failed to read or convert instructions file.',
        ];
    }

    $tasks = json_decode($jsonContent, true);
    if (is_null($tasks)) {
        return [
            'Deployment started. Processing instructions...',
            'Invalid JSON in instructions file.',
        ];
    }
    $errInstructions = validateInstructions($tasks);
    if ($errInstructions !== true) {
        return [
            "Deployment started. Error in instructions: $errInstructions",
            $errInstructions,
        ];
    }
    if (json_last_error() !== JSON_ERROR_NONE) {
        return [
            'Deployment started. Processing instructions...',
            'Invalid JSON in instructions file.',
        ];
    }

    return null;
}

// ORIGINAL
/*
 * function executeDeployment()
 * {
 * global $config, $statusFile;
 *
 * // Validate required config variables
 * $requiredVars = [
 * 'project_path' => $config['project_path'],
 * 'instructions' => $config['instructions'],
 * ];
 * foreach ($requiredVars as $label => $value) {
 * if (empty($value) || ! file_exists($value) && $label === 'project_path') {
 * http_response_code(400);
 * exit("Error: Configuración inválida o faltante: $label");
 * }
 * }
 *
 * if (file_exists($statusFile)) {
 * $current = json_decode(file_get_contents($statusFile), true);
 * // Si el proceso anterior ya terminó, borramos el lock viejo para permitir el nuevo
 * if (isset($current['finished']) && $current['finished'] === true) {
 * unlink($statusFile);
 * } else {
 * http_response_code(409);
 * exit('Deployment already in progress.');
 * }
 * }
 *
 * $startTime = microtime(true);
 * $logFilename = 'deploy_'.date('Ymd_His').'.log';
 * $logPath = $config['logs_path'].'/'.$logFilename;
 * $logPathRaw = $config['logs_path'].'/'.$logFilename.'.rlog';
 * if (! file_exists($config['instructions']))
 * exit('Instruction file missing.');
 *
 * $jsonContent = file_get_contents($config['instructions']);
 * // Validate if the instructions file has valid JSON and a valid format:
 * $tasks = json_decode($jsonContent, true);
 * if (is_null($tasks)) {
 * sendTelegram(
 * buildReport(
 * $_SERVER['HTTP_HOST'] ?? 'localhost',
 * false,
 * 0,
 * '',
 * '',
 * 'Deployment started. Processing instructions...',
 * ),
 * );
 * exit('Invalid JSON in instructions file.');
 * }
 * $errInstructions = validateInstructions($tasks);
 * if ($errInstructions !== true) {
 * sendTelegram(
 * buildReport(
 * $_SERVER['HTTP_HOST'] ?? 'localhost',
 * false,
 * 0,
 * '',
 * '',
 * "Deployment started. Error in instructions: $errInstructions",
 * ),
 * );
 * exit($errInstructions);
 * }
 *
 * if (json_last_error() !== JSON_ERROR_NONE)
 * exit('Invalid JSON in instructions file.');
 *
 * file_put_contents($statusFile, json_encode([
 * 'running' => true,
 * 'task' => 'Starting...',
 * 'index' => 0,
 * 'total' => count($tasks),
 * 'start' => $startTime,
 * ]));
 * chdir($config['project_path']);
 * $success = true;
 * $failedTask = '';
 * $fullLog = 'START: '.date('Y-m-d H:i:s')."\n";
 * $fullLogRaw = 'START: '.date('Y-m-d H:i:s')."\n";
 *
 * $taskStatus = array_fill(0, count($tasks), ['status' => 'pending', 'name' => '']);
 * foreach ($tasks as $i => $t) {
 * $taskStatus[$i]['name'] = $t['name'] ?? 'Task '.($i + 1);
 * }
 *
 * foreach ($tasks as $index => $task) {
 * $taskStatus[$index]['status'] = 'running';
 *
 * $name = $task['name'] ?? 'Unnamed Task';
 * $commands = is_array($task['run']) ? $task['run'] : [$task['run']];
 *
 * file_put_contents($statusFile, json_encode([
 * 'running' => true,
 * 'task' => $name,
 * 'index' => $index + 1,
 * 'total' => count($tasks),
 * 'start' => $startTime,
 * 'history' => $taskStatus,
 * ]));
 *
 * // Separators
 * $fullLog .= "\n---------------------------------------------\n";
 * $fullLogRaw .= "\n---------------------------------------------\n";
 * $fullLog .= "\n[TASK]: $name\n";
 * $taskSuccess = true;
 * $errorOutput = '';
 *
 * foreach ($commands as $cmd) {
 * $fullLog .= "[CMD]: $cmd\n";
 * $fullLogRaw .= '['.date('Y-m-d H:i:s')."] $cmd\n";
 *
 * $process = proc_open(
 * $cmd,
 * [
 * 0 => ['pipe', 'r'],
 * 1 => ['pipe', 'w'],
 * 2 => ['pipe', 'w'],
 * ],
 * $pipes,
 * );
 *
 * if (is_resource($process)) {
 * fclose($pipes[0]); // We don't need stdin
 *
 * $cmdStdout = '';
 * $cmdStderr = '';
 *
 * while (true) {
 * $read = [$pipes[1], $pipes[2]];
 * $write = null;
 * $except = null;
 *
 * $res = stream_select($read, $write, $except, 1);
 *
 * if ($res > 0) {
 * foreach ($read as $pipe) {
 * $content = fread($pipe, 8192);
 * if ($pipe === $pipes[1]) {
 * $cmdStdout .= $content;
 * $fullLogRaw .= $content;
 * } else {
 * $cmdStderr .= $content;
 * }
 * }
 * }
 *
 * if (feof($pipes[1]) && feof($pipes[2])) {
 * break;
 * }
 *
 * $status = proc_get_status($process);
 * if (! $status['running'] && $res === 0) {
 * break;
 * }
 * }
 *
 * fclose($pipes[1]);
 * fclose($pipes[2]);
 * $exitCode = proc_close($process);
 *
 * $fullLog .= "STDOUT: $cmdStdout\nSTDERR: $cmdStderr\nEXIT: $exitCode\n";
 *
 * if ($exitCode !== 0) {
 * $taskSuccess = false;
 * $errorOutput = $cmdStderr ?: $cmdStdout;
 * break;
 * }
 * } else {
 * $taskSuccess = false;
 * $errorOutput = "Failed to open process: $cmd";
 * $fullLog .= "ERROR: $errorOutput\n";
 * break;
 * }
 * }
 *
 * if ($taskSuccess) {
 * $taskStatus[$index]['status'] = 'success';
 * } else {
 * $success = false;
 * $failedTask = $name;
 * $taskStatus[$index]['status'] = 'failed';
 * // Guardamos el último estado antes de salir por error
 * file_put_contents($statusFile, json_encode([
 * 'running' => false, // Detener animación en live si falló
 * 'task' => "FAILED: $name",
 * 'index' => $index + 1,
 * 'total' => count($tasks),
 * 'history' => $taskStatus,
 * ]));
 * break;
 * }
 * }
 * // ================================================================================
 * $duration = round(microtime(true) - $startTime, 2);
 *
 * file_put_contents($logPath, $fullLog."\nEND. Duration: {$duration}s");
 * file_put_contents($logPathRaw, $fullLogRaw."\nEND. Duration: {$duration}s");
 *
 * // unlink($statusFile);
 * file_put_contents($statusFile, json_encode([
 * 'running' => false, // Is stopped
 * 'finished' => true,
 * 'success' => $success,
 * 'task' => $success ? 'Deployment Finished Successfully' : 'Deployment Failed',
 * 'index' => $index + 1,
 * 'total' => count($tasks),
 * 'start' => $startTime,
 * 'duration' => $duration,
 * 'history' => $taskStatus,
 * 'log_file' => $logFilename,
 * ]));
 *
 * $protocol = isset($_SERVER['HTTPS']) ? 'https://' : 'http://';
 *
 * $host = defined('CLI_HOST') ? CLI_HOST : $_SERVER['HTTP_HOST'] ?? 'localhost';
 * $logfileNameWithoutExt = pathinfo($logFilename, PATHINFO_FILENAME);
 * $logUrl = "$protocol{$host}/log/rview/{$logfileNameWithoutExt}";
 *
 * sendTelegram(
 * buildReport(
 * $host,
 * $success,
 * $duration,
 * $logUrl,
 * $failedTask ?? '',
 * $errorOutput ?? '',
 * ),
 * );
 * if (! isset($_GET['manual']))
 * echo 'Done.';
 * }
 */

function executeDeploymentWithSingleShellProccess()
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

    // Validate Status file for deployments in progress
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

    // Validate instructions file and format
    if (! file_exists($config['instructions']))
        exit('Instruction file missing.');

    // Validate if the instructions file has valid JSON and a valid format:
    $errorValue = validateJsonContent();
    if ($errorValue) {
        sendTelegram(
            buildReport(
                $_SERVER['HTTP_HOST'] ?? 'localhost',
                false,
                0,
                '',
                '',
                $errorValue[0] ?? 'Error in instructions',
            ),
        );
        exit($errorValue[1] ?? 'Error in instructions');
    }

    // Setup tasks logs
    $logFileName = 'deploy_'.date('Ymd_His').'.log';

    $logFilePath = $config['logs_path'].'/'.$logFileName;
    $logFilePathRaw = $config['logs_path'].'/'.$logFileName.'.rlog';
    $logFIlePathHTML = $config['logs_path'].'/'.$logFileName.'.html';

    // Get tasks in the instructions file
    $jsonContent = getInstructionsContent();
    $tasks = json_decode($jsonContent, true);

    runTasks($logFilePath, $logFilePathRaw, $logFIlePathHTML, $tasks);
}

function createLogHtml($title, $bodyContent)
{
    // @mago-format-ignore-next
    return <<<HTML
<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>$title</title>
        <style>
            body {
                font-family: Arial, sans-serif;
                padding: 10px;
                background-color: #f9f9f9;
            }
            h1, h2, h3 {
                color: #333;
            }
            pre {
                background-color: #f0f0f0;
                padding: 10px;
                border-radius: 5px;
                overflow-x: auto;
            }
            code {
                font-family: 'Courier New', Courier, monospace;
            }
        </style>
    </head>
    <body>
        $bodyContent
    </body>
</html>
HTML;
}

function updateLiveStatus($data)
{
    global $statusFile;
    file_put_contents($statusFile, json_encode($data));
}

function runTasks(
    string $lofgilePath,
    string $logFilePathRaw,
    string $logFilePathHTML,
    array $tasks,
) {
    global $statusFile, $config;

    // Setup log files
    $startTime = microtime(true);

    //stes
    $totalTaks = count($tasks);

    // Init status file for live view
    $statusData = [
        'running' => true,
        'task' => 'Starting...',
        'index' => 0,
        'total' => $totalTaks,
        'start' => $startTime,
        'current_output' => '',
    ];
    updateLiveStatus($statusData);

    // Change execution directory to project path
    // NOTE: replazable for the action in proc_open
    // chdir($config['project_path']);
    $cmdsCWD = $config['project_path'];

    // initialize the flags
    $success = true;
    $failedTask = '';
    $fullLog = 'START: '.date('Y-m-d H:i:s')."\n";
    $fullLogRaw = 'START: '.date('Y-m-d H:i:s')."\n";
    $htmlLogContent = '<h1>Deployment Log - Started at '.date('Y-m-d H:i:s')."</h1>\n";

    // Init the statusfile with pending status for all tasks
    $taskStatus = [];
    foreach ($tasks as $i => $t) {
        $taskStatus[$i] = [
            'name' => $t['name'] ?? 'Task '.($i + 1),
            'status' => 'pending',
            'output' => '',
        ];
    }
    $statusData['history'] = $taskStatus;
    updateLiveStatus($statusData);

    // Start the execution of tasks
    $descriptor = [
        0 => ['pipe', 'r'], // stdin
        1 => ['pipe', 'w'], // stdout
        2 => ['pipe', 'w'], // stderr
    ];
    // start process shell
    $process = proc_open(
        'stdbuf -o0 -e0 bash',
        $descriptor,
        $pipes,
        realpath($cmdsCWD),
    );
    if (! is_resource($process)) {
        file_put_contents($lofgilePath, "ERROR: Failed to start shell process\n", FILE_APPEND);
        file_put_contents($logFilePathRaw, "ERROR: Failed to start shell process\n", FILE_APPEND);
        die('Failed to start shell process');
    }

    // Set non-blocking mode for stdout and stderr
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    // Execute tasks sequentially
    foreach ($tasks as $indx => $task) {
        // Total outputs
        // NOTE: supressit
        $stdout = '';
        $stderr = '';

        // update in task to running state
        $taskStatus[$indx]['status'] = 'running';
        $taskToRunCommands = $task['run'];
        $taskToRunName = $task['name'] ?? 'Task #'.($indx + 1);
        $commandsToRun = is_array($taskToRunCommands) ? $taskToRunCommands : [$taskToRunCommands];

        // Update staus file for live view
        $statusData['task'] = $taskToRunName;
        $statusData['index'] = $indx + 1;
        $statusData['history'] = $taskStatus;
        $statusData['current_output'] = ''; // Reset output for each task
        updateLiveStatus($statusData);

        // Put separators
        $fullLog .= "\n+---------------------------------------------+\n";
        $fullLogRaw .= "\n+---------------------------------------------+\n";
        $htmlLogContent .= '<hr>';
        $fullLog .= "[TASK]: $taskToRunName";
        $htmlLogContent .= "<h2>$taskToRunName</h2>\n";

        $taskSuccess = true;
        $errorOutput = '';

        foreach ($commandsToRun as $cmd) {
            $fullLog .= "\n[CMD]: $cmd\n";
            $fullLogRaw .= '['.date('Y-m-d H:i:s')."] $cmd\n";
            $cmdHtml = explode('\\n', $cmd);
            $cmdHtml = implode("<br>", array_map('htmlspecialchars', $cmdHtml));
            $htmlLogContent .= "<h3>Command: $cmdHtml</h3>\n";

            // Wrap multiline scripts properly
            $wrapped = sprintf(
                "{\n%s\n}\n__exit__=$?\necho '__STDOUT_EOF__'\"\$__exit__\"\necho '__STDERR_EOF__'\"\$__exit__\" >&2\n",
                $cmd
            );
            fwrite($pipes[0], $wrapped); // Send command to shell

            $stdoutDone = false;
            $stderrDone = false;
            $exitCode = 0; // Success by default
            $timeoutCounter = 0;
            $maxTimeoutIterations = 100; // ~500 seconds max per command

            $htmlLogContent .= "<pre style='background:#f0f0f0;padding:10px;border-radius:5px;'><code>";
            while (! $stdoutDone || ! $stderrDone) {
                $read = array_filter([$pipes[1], $pipes[2]]);
                $write = null;
                $except = null;

                $streamResult = stream_select($read, $write, $except, 5);
                
                if ($streamResult === false) {
                    // stream_select error
                    $stderr .= "[ERROR] stream_select failed\n";
                    break;
                }

                if ($streamResult === 0) {
                    // Timeout occurred
                    $timeoutCounter++;
                    if ($timeoutCounter >= $maxTimeoutIterations) {
                        $stderr .= "[ERROR] Command timeout after 500 seconds\n";
                        $exitCode = 124; // Standard timeout exit code
                        break;
                    }
                    continue;
                }

                $hasOutput = false;
                foreach ($read as $stream) {
                    $line = fgets($stream);
                    if ($line === false)
                        continue;

                    $hasOutput = true;
                    if ($stream === $pipes[1]) {
                        // Match __STDOUT_EOF__123 format
                        if (preg_match('/^__STDOUT_EOF__(.*)$/', trim($line), $m)) {
                            $exitCode = (int) $m[1];
                            $stdoutDone = true;
                        } else {
                            $stdout .= $line;
                            $statusData['current_output'] .= $line;
                            $taskStatus[$indx]['output'] .= $line;
                            $fullLogRaw .= '['.date('Y-m-d H:i:s')."][info  ] $line";
                            $htmlLogContent .= htmlspecialchars($line);
                        }
                    } else {
                        // Match __STDERR_EOF__123 format
                        if (preg_match('/^__STDERR_EOF__(.*)$/', trim($line), $m)) {
                            $stderrDone = true;
                        } else {
                            $stderr .= $line;
                            $statusData['current_output'] .= $line;
                            $taskStatus[$indx]['output'] .= $line;
                            $fullLogRaw .= '['.date('Y-m-d H:i:s')."][error ] $line";
                            $htmlLogContent .= "<span style='color:red;'>".htmlspecialchars($line).'</span>';
                        }
                    }
                }

                if ($hasOutput) {
                    $statusData['history'] = $taskStatus;
                    updateLiveStatus($statusData);
                }
                
                // Reset timeout counter if we got output
                if ($hasOutput) {
                    $timeoutCounter = 0;
                }
            }

            $htmlLogContent .= "</code></pre>\n";
            $fullLog .= "STDOUT: $stdout\nSTDERR: $stderr\nEXIT: $exitCode\n";

            if ($exitCode !== 0) {
                $taskSuccess = false;
                $errorOutput = $stderr ?: $stdout;
                break;
            }
        }

        if ($taskSuccess) {
            $taskStatus[$indx]['status'] = 'success';
        } else {
            $success = false;
            $failedTask = $taskToRunName;
            $taskStatus[$indx]['status'] = 'failed';
            // Guardamos el último estado antes de salir por error
            $statusData['running'] = false;
            $statusData['task'] = "FAILED: $taskToRunName";
            $statusData['history'] = $taskStatus;
            updateLiveStatus($statusData);
            break;
        }
    }

    // ================================================================================

    $fullLog .= "\n=============================================\n";
    $fullLogRaw .= "\n=============================================\n";

    $duration = round(microtime(true) - $startTime, 2);

    $htmlLogContent .= "<h2>Deployment finished in {$duration}s</h2>\n";
    $htmlLogContent .= '<p><strong>Overall Status: '.($success ? 'SUCCESS' : "FAILED at $failedTask")."</strong></p>\n";

    // Update logs after each task
    file_put_contents($lofgilePath, $fullLog."\nEND. Duration: {$duration}s");
    file_put_contents($logFilePathRaw, $fullLogRaw."\nEND. Duration: {$duration}s");
    file_put_contents($logFilePathHTML, createLogHtml("Deployment Log - $taskToRunName", $htmlLogContent));

    // Update status
    updateLiveStatus([
        'running' => false,
        'finished' => true,
        'success' => $success,
        'task' => $success ? 'Deployment Finished Successfully' : 'Deployment Failed',
        'index' => $indx + 1,
        'total' => $totalTaks,
        'start' => $startTime,
        'duration' => $duration,
        'history' => $taskStatus,
        'log_file' => basename($lofgilePath),
    ]);

    $protocol = isset($_SERVER['HTTPS']) ? 'https://' : 'http://';
    $host = defined('CLI_HOST') ? CLI_HOST : $_SERVER['HTTP_HOST'] ?? 'localhost';
    $logFileNameWithoutExt = pathinfo($lofgilePath, PATHINFO_FILENAME);
    $logUrl = "$protocol{$host}/log/rview/{$logFileNameWithoutExt}";

    sendTelegram(
        buildReport(
            $host,
            $taskSuccess,
            $duration,
            $logUrl,
            $taskToRunName,
            $errorOutput,
        ),
    );

    if (! isset($_GET['manual']))
        echo 'Done.';
}

function sendTelegram($text)
{
    global $config;

    if (! $config['telegram_enabled'])
        return false;

    $botTkn = $config['bot_token'] ?? '';
    if (empty($botTkn))
        return false;

    $ch = curl_init("https://api.telegram.org/bot{$botTkn}/sendMessage");

    $payload = [
        'chat_id' => $config['chat_id'],
        'parse_mode' => 'MarkdownV2',
        'text' => $text,
    ];
    if ($config['thread_id']) {
        $payload['message_thread_id'] = $config['thread_id'];
    }

    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $response = curl_exec($ch);
    $error = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($response === false || $httpCode !== 200) {
        $logEntry = sprintf(
            "[%s] HTTP Code: %s | Error: %s | Response: %s | Params: %s\n",
            date('Y-m-d H:i:s'),
            $httpCode,
            $error,
            $response,
            json_encode($payload),
        );
        file_put_contents($config['logs_path'].'/telegram_errors.log', $logEntry, FILE_APPEND);

        return false;
    }

    return true;
}

function prugueStrData($str)
{
    return str_replace(
        ['\\', '_', '*', '[', ']', '(', ')', '~', '`', '>', '#', '+', '-', '=', '|', '{', '}', '.', '!'],
        [
            '\\\\',
            '\\_',
            '\\*',
            '\\[',
            '\\]',
            '\\(',
            '\\)',
            '\\~',
            '\\`',
            '\\>',
            '\\#',
            '\\+',
            '\\-',
            '\\=',
            '\\|',
            '\\{',
            '\\}',
            '\\.',
            '\\!',
        ],
        $str,
    );
}

function buildReport($appName, $success, $duration, $logUrl, $failedTask = '', $errorOutput = '')
{
    $emoji = $success ? '✅' : '❌';
    // decode $failedTask for markdown special characters
    if (! empty($failedTask)) {
        $failedTask = prugueStrData($failedTask);
    }
    if (! empty($errorOutput)) {
        $errorOutput = "\n*Error Output:*\n```\n".prugueStrData($errorOutput)."\n```";
    }
    $status = $success ? 'SUCCESS' : "FAILED at $failedTask";

    $duration = str_replace('.', '\.', $duration.'');

    // @mago-format-ignore-next
    return <<<MARKDOWN
*$emoji SPHPD:* `$appName`

*Status:* _{$status}_
*Duration:* _{$duration}s_
*Log:* [View Details]($logUrl)
$errorOutput
MARKDOWN;
}

function showSpecificLog($file, $type = 'text/plain')
{
    global $config;
    $path = realpath($config['logs_path'].'/'.$file);
    if ($path && str_starts_with($path, realpath($config['logs_path']))) {
        // header('Content-Type: text/plain');
        header("Content-Type: $type");
        readfile($path);
    } else
        exit('Access Denied');
}

function renderValidationError($errorMessage)
{
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <script src="https://unpkg.com/@tailwindcss/browser@4"></script>
        <title>Deployment Configuration Error</title>
        <?= renderHeadImports() ?>
    </head>
    <body class="bg-[#f8fafc] dark:bg-[#0b0f1a] text-slate-600 dark:text-slate-300 p-8 font-mono text-sm transition-colors duration-200">
        <div class="max-w-2xl mx-auto">
            <div class="mt-8 bg-rose-50 dark:bg-rose-950/30 border border-rose-200 dark:border-rose-500/50 p-8 rounded-lg shadow-lg dark:shadow-xl">
                <div class="flex items-start gap-4">
                    <div class="text-4xl">❌</div>
                    <div class="flex-1">
                        <h1 class="text-xl font-bold text-rose-900 dark:text-rose-100 mb-4">Deployment Configuration Error</h1>
                        <p class="text-rose-800 dark:text-rose-200 mb-6 leading-relaxed">
                            <?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?>
                        </p>
                        <div class="flex gap-3">
                            <a href="/health" class="bg-rose-600 hover:bg-rose-700 text-white px-4 py-2 rounded text-sm font-bold transition">
                                Back to Dashboard
                            </a>
                            <button onclick="window.history.back()" class="border border-rose-300 dark:border-rose-500/50 hover:bg-rose-50 dark:hover:bg-rose-900/30 text-rose-700 dark:text-rose-200 px-4 py-2 rounded text-sm font-bold transition">
                                Go Back
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="mt-8 bg-white dark:bg-[#161b2a] border border-slate-200 dark:border-slate-800 p-6 rounded-lg shadow-sm dark:shadow-xl">
                <h2 class="text-slate-400 dark:text-slate-500 text-[10px] font-bold mb-4 border-b border-slate-100 dark:border-slate-800 pb-2 uppercase tracking-widest">Common Issues & Solutions</h2>
                <div class="space-y-4 text-sm">
                    <div>
                        <h3 class="font-bold text-slate-700 dark:text-slate-300 mb-2">Missing YQ_PATH Variable</h3>
                        <p class="text-slate-600 dark:text-slate-400 mb-2">If using a YAML file (deploy.yml), you need to set the YQ_PATH environment variable:</p>
                        <pre class="bg-slate-50 dark:bg-slate-950 p-3 rounded text-xs border border-slate-200 dark:border-slate-800">YQ_PATH=/usr/bin/yq</pre>
                    </div>
                    <div>
                        <h3 class="font-bold text-slate-700 dark:text-slate-300 mb-2">Invalid Instructions Format</h3>
                        <p class="text-slate-600 dark:text-slate-400 mb-2">Check your deploy.json or deploy.yml file format:</p>
                        <pre class="bg-slate-50 dark:bg-slate-950 p-3 rounded text-xs border border-slate-200 dark:border-slate-800">[
  { "name": "Task 1", "run": "command" },
  { "name": "Task 2", "run": "command" }
]</pre>
                    </div>
                    <div>
                        <h3 class="font-bold text-slate-700 dark:text-slate-300 mb-2">Missing Configuration</h3>
                        <p class="text-slate-600 dark:text-slate-400">Ensure your .env file has INSTRUCTIONS_FILE and PROJECT_PATH set correctly.</p>
                    </div>
                </div>
            </div>
        </div>
    </body>
    </html>
    <?php
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

function showLastLogHtml()
{
    global $config;
    $logs = glob($config['logs_path'].'/*.html');
    if (! $logs)
        exit('No HTML logs available.');
    usort($logs, fn ($a, $b) => filemtime($b) - filemtime($a));
    header('Content-Type: text/html');
    readfile($logs[0]);
}

function clearHistory()
{
    global $config, $statusFile;

    // $logs = glob($config['logs_path'].'/*.log');
    $logs = glob($config['logs_path'].'/*');
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
// ================================================================================

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
                        const response = await fetch('/status/check');
                        const data = await response.json();

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

                            <?php if (env('MODE', 'production') !== 'production'): ?>
                                <a href="/debugdeploy" 
                                    target="_blank"
                                    onclick="return confirm('Debug deployment?')"
                                    class="block w-full text-center bg-amber-800 dark:bg-amber-800 text-white py-2 rounded text-xs font-bold hover:bg-amber-700 dark:hover:bg-amber-700 transition">DEBUG DEPLOYMENT</a>
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
                        <a target="_blank" href="/log/last" class="text-[10px] text-indigo-600 dark:text-indigo-400 hover:underline font-bold uppercase tracking-tighter transition">View Latest Raw</a>
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
                                    $logName = pathinfo($fn, PATHINFO_FILENAME);
                                    $content = file_get_contents($logPath);
                                    // $isOk = str_contains($content, 'Status: SUCCESS');
                                    // get the penultimate line of the log
                                    $lines = preg_split('/\r\n|\r|\n/', trim($content));
                                    $linesQuantity = count($lines);
                                    $exitLastStatus = $lines[$linesQuantity - 5] ?? '';
                                    $isOk = str_contains($exitLastStatus, 'EXIT: 0');
                                    // var_dump($exitLastStatus);

                                    ?>
                                    <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/20 transition">
                                        <td class="px-6 py-4 text-xs">
                                            <span class="mr-2 <?= $isOk ? 'text-emerald-500' : 'text-rose-500' ?>">●</span>
                                            <?= $fn ?>
                                        </td>

                                        <td class="px-6 py-4 text-slate-400 dark:text-slate-600 text-xs"><?= date(
                                            'Y-m-d H:i:s',
                                            filemtime($logPath),
                                        ) ?></td>
                                        <td class="px-6 py-4 text-right">
                                            <a target="_blank" href="/log/rview/<?= urlencode($logName) ?>" class="text-blue-600 dark:text-blue-500 hover:underline font-bold text-xs transition">OPEN</a>
                                            <a target="_blank" href="/log/bview/<?= urlencode($logName) ?>" class="text-blue-600 dark:text-blue-500 hover:underline font-bold text-xs transition">RAW</a>
                                            <a target="_blank" href="/log/htmlview/<?= urlencode($logName) ?>" class="text-blue-600 dark:text-blue-500 hover:underline font-bold text-xs transition">HTML</a>
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
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <script src="https://unpkg.com/@tailwindcss/browser@4"></script>
        <title>Execution Status</title>
        <?= renderHeadImports() ?>
        <script>
            async function updateStatus() {
                try {
                    const response = await fetch('/status/data');
                    const data = await response.json();
                    
                    // Update task name and progress
                    document.getElementById('current-task').textContent = data.task;
                    document.getElementById('progress-text').textContent = `${data.index}/${data.total}`;
                    
                    // Update progress bar
                    const progressPercent = data.running || data.finished ? (data.index / data.total) * 100 : ((data.index - 1) / data.total) * 100;
                    document.getElementById('progress-bar').style.width = progressPercent + '%';

                    // Update stop button visibility
                    const stopButton = document.getElementById('stop-button');
                    if (data.running) {
                        stopButton.classList.remove('hidden');
                    } else {
                        stopButton.classList.add('hidden');
                    }

                    // Update history with expandable outputs
                    // Save current state of open collapsibles
                    document.querySelectorAll('#history-container > div > div[id^="task-output-"]').forEach(el => {
                        if (!el.classList.contains('hidden')) {
                            openCollapsibles.add(el.id);
                        }
                    });

                    const historyContainer = document.getElementById('history-container');
                    historyContainer.innerHTML = '';
                    data.history.forEach((item, i) => {
                        const status = item.status;
                        const name = item.name;
                        const output = item.output || '';

                        let badgeClass = '';
                        let label = '';
                        let rowClass = 'border-transparent';
                        let bounce = '';

                        switch(status) {
                            case 'success':
                                badgeClass = 'bg-emerald-500/10 text-emerald-500 border-emerald-500/20';
                                label = 'DONE';
                                break;
                            case 'failed':
                                badgeClass = 'bg-rose-500/10 text-rose-500 border-rose-500/20 animate-pulse';
                                label = 'FAIL';
                                break;
                            case 'running':
                                badgeClass = 'bg-blue-500/10 text-blue-500 border-blue-500/40 border';
                                label = 'BUSY';
                                rowClass = 'border-blue-500/20 bg-blue-500/5';
                                bounce = `<div class="flex gap-1">
                                            <span class="w-1 h-1 bg-blue-500 rounded-full animate-bounce"></span>
                                            <span class="w-1 h-1 bg-blue-500 rounded-full animate-bounce [animation-delay:0.2s]"></span>
                                            <span class="w-1 h-1 bg-blue-500 rounded-full animate-bounce [animation-delay:0.4s]"></span>
                                          </div>`;
                                break;
                            default:
                                badgeClass = 'bg-slate-100 dark:bg-slate-800/50 text-slate-400 border-transparent';
                                label = 'WAIT';
                        }

                        const textClass = status === 'running' ? 'text-slate-900 dark:text-white font-bold' : (status === 'pending' ? 'text-slate-500' : 'text-slate-700 dark:text-slate-400');
                        const toggleId = `task-output-${i}`;
                        const isRunning = status === 'running';
                        const isOpen = openCollapsibles.has(toggleId);

                        historyContainer.innerHTML += `
                            <div class="border border-slate-200 dark:border-slate-700 rounded-lg overflow-hidden">
                                <button onclick="document.getElementById('${toggleId}').classList.toggle('hidden'); this.querySelector('.toggle-arrow').classList.toggle('rotate-180'); if (document.getElementById('${toggleId}').classList.contains('hidden')) { openCollapsibles.delete('${toggleId}'); } else { openCollapsibles.add('${toggleId}'); }" class="w-full flex items-center justify-between p-3 hover:bg-slate-50 dark:hover:bg-slate-800/50 transition">
                                    <div class="flex items-center gap-3">
                                        <span class="text-[10px] px-2 py-0.5 rounded font-bold border uppercase ${badgeClass}">
                                            ${label}
                                        </span>
                                        <span class="text-xs ${textClass}">
                                            ${name}
                                        </span>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        ${bounce}
                                        <svg class="toggle-arrow w-4 h-4 text-slate-400 transition-transform ${isOpen ? '' : 'rotate-180'}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 14l-7 7m0 0l-7-7m7 7V3"></path>
                                        </svg>
                                    </div>
                                </button>
                                <div id="${toggleId}" class="${isOpen ? '' : 'hidden'} bg-slate-50 dark:bg-slate-900/50 border-t border-slate-200 dark:border-slate-700 p-3">
                                    <pre class="text-[10px] text-slate-700 dark:text-slate-300 bg-white dark:bg-slate-950 p-3 rounded border border-slate-200 dark:border-slate-800 overflow-x-auto max-h-64 overflow-y-auto font-mono whitespace-pre-wrap break-words">${output || '(no output)'}</pre>
                                </div>
                            </div>
                        `;
                    });

                    // Update output terminal
                    const outputTerminal = document.getElementById('output-terminal');
                    if (data.current_output) {
                        outputTerminal.textContent = data.current_output;
                        outputTerminal.scrollTop = outputTerminal.scrollHeight;
                    }

                    // Update footer and status messages
                    const statusText = document.getElementById('status-text');
                    if (data.running) {
                        statusText.textContent = 'System executing instructions...';
                    } else {
                        statusText.textContent = 'Process halted. Check logs.';
                    }

                    if (data.finished) {
                        const resultContainer = document.getElementById('result-container');
                        resultContainer.classList.remove('hidden');
                        resultContainer.className = `mb-6 p-4 rounded-lg border ${data.success ? 'bg-emerald-500/10 border-emerald-500/20 text-emerald-500' : 'bg-rose-500/10 border-rose-500/20 text-rose-500'} flex justify-between items-center uppercase text-[10px] font-bold tracking-widest`;
                        resultContainer.innerHTML = `<span>${data.success ? '✓ Deployment Completed' : '✕ Deployment Failed'}</span><span>Duration: ${data.duration}s</span>`;
                        
                        document.getElementById('action-buttons').innerHTML = `
                            <a href="/health" class="bg-slate-800 text-white px-4 py-2 rounded text-[10px] font-bold hover:bg-slate-700 transition">BACK TO DASHBOARD</a>
                            <a href="/log/view?file=${encodeURIComponent(data.log_file)}" class="border border-slate-200 dark:border-slate-800 px-4 py-2 rounded text-[10px] font-bold hover:bg-slate-50 dark:hover:bg-slate-800 transition">VIEW FULL LOG</a>
                        `;
                        
                        // Stop polling if finished
                        clearInterval(pollInterval);
                    }

                } catch (error) {
                    console.error('Error fetching status:', error);
                }
            }

            async function stopDeployment() {
                if (!confirm('Are you sure you want to stop the deployment?')) {
                    return;
                }
                
                try {
                    const response = await fetch('/deploy/stop', { method: 'POST' });
                    const data = await response.json();
                    
                    if (data.success) {
                        alert('Deployment stopped successfully');
                        updateStatus();
                    } else {
                        alert('Failed to stop deployment: ' + data.message);
                    }
                } catch (error) {
                    console.error('Error stopping deployment:', error);
                    alert('Error stopping deployment');
                }
            }

            // Track which collapsibles are open
            const openCollapsibles = new Set();

            const pollInterval = setInterval(updateStatus, 1000);
            window.onload = updateStatus;
        </script>
    </head>
    <body class="bg-[#f8fafc] dark:bg-[#0b0f1a] text-slate-600 dark:text-slate-400 min-h-screen font-mono p-6 transition-colors duration-200">
        <div class="w-full max-w-6xl mx-auto">
            
            <!-- Header Section -->
            <div class="mb-8 flex justify-between items-end border-b border-slate-200 dark:border-slate-700 pb-6">
                <div>
                    <div class="text-[10px] text-slate-400 dark:text-slate-500 mb-1 uppercase tracking-[0.2em]">Current Progress</div>
                    <div id="current-task" class="text-2xl text-slate-900 dark:text-white font-bold tracking-tight">
                        <?= $data['task'] ?>
                    </div>
                </div>
                <div class="text-right">
                    <span id="progress-text" class="text-3xl font-black text-slate-300 dark:text-slate-700"><?= $data['index'] ?>/<?= $data['total'] ?></span>
                </div>
            </div>

            <!-- Progress Bar -->
            <div class="relative mb-6">
                <div class="overflow-hidden h-2 text-xs flex rounded bg-slate-200 dark:bg-slate-800">
                    <div id="progress-bar" style="width:<?= (($data['index'] - 1) / $data['total']) * 100 ?>%" class="shadow-none flex flex-col text-center whitespace-nowrap text-white justify-center bg-blue-600 transition-all duration-500"></div>
                </div>
            </div>

            <!-- Two-Column Layout: Task List + Real-time Output -->
            <div class="grid grid-cols-2 gap-6 mb-8">
                
                <!-- Left Column: Task List with Expandable Outputs -->
                <div class="flex flex-col">
                    <div class="text-[10px] text-slate-400 dark:text-slate-500 mb-3 uppercase tracking-[0.2em] font-bold">Task Execution Timeline</div>
                    <div id="history-container" class="space-y-2 overflow-y-auto max-h-96 pr-2">
                        <!-- History items will be inserted here by JS -->
                    </div>
                </div>

                <!-- Right Column: Real-time Output Terminal -->
                <div class="flex flex-col">
                    <div class="text-[10px] text-slate-400 dark:text-slate-500 mb-3 uppercase tracking-[0.2em] font-bold">Real-time Output</div>
                    <pre id="output-terminal" class="flex-1 overflow-y-auto p-4 bg-slate-900 text-slate-300 text-[10px] rounded-lg border border-slate-800 font-mono whitespace-pre-wrap break-words max-h-96"></pre>
                </div>

            </div>

            <!-- Status and Action Section -->
            <div class="bg-white dark:bg-[#161b2a] border border-slate-200 dark:border-slate-800 rounded-lg p-6">
                <div class="flex justify-between items-center mb-4">
                    <p id="status-text" class="text-[9px] text-slate-400 dark:text-slate-600 uppercase tracking-widest italic">
                        System executing instructions...
                    </p>
                    <a href="/health" class="text-[10px] text-blue-500 hover:underline">Exit Live View</a>
                </div>

                <div id="result-container" class="hidden mb-4"></div>

                <div id="action-buttons" class="flex justify-between items-center border-t border-slate-200 dark:border-slate-700 pt-4">
                    <p class="text-[9px] text-slate-400 italic animate-pulse tracking-widest">SYNCING WITH SERVER...</p>
                    <button id="stop-button" onclick="stopDeployment()" class="hidden bg-rose-600 hover:bg-rose-700 text-white px-4 py-2 rounded text-[10px] font-bold transition">
                        ⏹ STOP DEPLOYMENT
                    </button>
                </div>
            </div>

        </div>
    </body>
    </html>
    <?php
}
