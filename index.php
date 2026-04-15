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

// CLASS
// ================================================================================

class ArifactContract
{
    public function __construct(
        public readonly string $project_id,
        public readonly string $branch,
        public readonly string $job,
        public readonly string $job_id,
    ) {}
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
    'gitlab_token' => env('GITLAB_TOKEN') ?? '',
    'gitlab_base_url' => env('GITLAB_BASE_URL') ?? 'https://gitlab.com',
    'artifact_deploy_dir' => env('ARTIFACT_DEPLOY_DIR') ?? __DIR__.'/artifact-deploy',
    'artifact_instructions' => env('ARTIFACT_INSTRUCTIONS_FILE') ?? 'artifact-deploy.json',
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

// Execute artifact deployment if called from CLI
if (isset($argv[1]) && $argv[1] === 'run-artifact-deploy') {
    define('CLI_HOST', $argv[2] ?? 'localhost');
    executeArtifactDeployment(
        $argv[3] ?? null,
        $argv[5] ?? 'build',
        $argv[6] ?? null,
        $argv[4] ?? 'main',
    );
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

// ROUTES
// ================================================================================

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
$router->add('/webhook/artifact-deploy', 'actionWebhookArtifactDeploy');
$router->add('/webhook/artifact-deploy/nowait', 'actionWebhookArtifactDeployNoWait');

$router->add('/alllogs', 'actionLogsView');
$router->add('/log/view', 'actionLogView');
$router->add('/log/rview/([a-zA-Z0-9_]+)', 'actionLogRawView');
$router->add('/log/bview/([a-zA-Z0-9_]+)', 'actionLogBaseRawView');
$router->add('/log/htmlview/([a-zA-Z0-9_]+)', 'actionLogHtmlView');
$router->add('/log/frawview/([a-zA-Z0-9_]+)', 'actionLogFRawView');
$router->add('/log/last', 'actionLogLast');
// $router->add('/latest', 'actionLogLatestHtml');
$router->add('/log/lasthtml', 'actionLogLatestHtml');
$router->add('/log/lastfraw', 'actionLogLastFRaw');
$router->add('/status/live', 'actionStatusLive');
$router->add('/deploy/stop', 'actionDeployStop');
$router->add('/test-notify', 'actionNotifyTest');
$router->add('/clear-history', 'actionClearHistory');

$router->add('404', function () {
    header('HTTP/1.0 404 Not Found');
    echo '404 Route Not Found';
});

// 2. Router init
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

// ---------------------------
function actionWebhookArtifactDeploy()
{
    global $method, $config;

    $dataArtifact = checkArtifact($method, $config);

    executeArtifactDeployment(
        $dataArtifact->project_id,
        $dataArtifact->job,
        $dataArtifact->job_id,
        $dataArtifact->branch,
    );
}

function actionWebhookArtifactDeployNoWait()
{
    global $config, $method;
    validateSecurity();

    $dataArtiact = checkArtifact($method, $config);

    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    exec(
        'php '
        .__FILE__
        .' run-artifact-deploy '
        .escapeshellarg($host)
        .' '
        .escapeshellarg($dataArtiact->project_id)
        .' '
        .escapeshellarg($dataArtiact->branch)
        .' '
        .escapeshellarg($dataArtiact->job)
        .' '
        .escapeshellarg($dataArtiact->job_id)
        .' > /dev/null 2>&1 &',
    );

    http_response_code(202);
    header('Content-Type: application/json');
    echo
        json_encode([
            'status' => 'accepted',
            'message' => 'Artifact deployment initiated in background',
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

function actionLogsView()
{
    renderLogsView();
}

function actionLogFRawView($id)
{
    $file = $id.'.log.fraw';
    showSpecificLog($file);
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

function actionLogLastFRaw()
{
    showLastLogFRaw();
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
    if (! file_exists($statusFile)) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'No deployment in progress']);
        exit();
    }

    $statusData = json_decode(file_get_contents($statusFile), true);

    // Check if it's actually running
    if (! isset($statusData['running']) || ! $statusData['running']) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'No deployment running']);
        exit();
    }

    // Kill the tracked process by PID (covers download/extract phases for artifact deploy)
    if (! empty($statusData['pid'])) {
        $pid = (int) $statusData['pid'];
        shell_exec("kill -TERM $pid 2>/dev/null; sleep 1; kill -KILL $pid 2>/dev/null");
    }

    // Kill any child shell process (covers task execution phase for both deploy types)
    shell_exec("pkill -f 'stdbuf -o0 -e0 bash' 2>/dev/null");

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

// Aux functions
// ================================================================================

/**
 * Checks the incoming artifact deployment request, validates it, logs it, and returns a structured contract object.
 * @param string $method
 * @param mixed $config
 * @return ArifactContract
 */
function checkArtifact(string $method, $config): ArifactContract
{
    validateSecurity();
    // Allow just post method
    if ($method !== 'POST') {
        http_response_code(405);
        exit('Method Not Allowed');
    }

    // Log requests
    $rawInput = file_get_contents('php://input');
    logRequestToFile($config['logs_path'].'/reqs.log', $rawInput);

    // validate
    $input = json_decode($rawInput, true);
    $projectId = $input['project_id'] ?? null;
    $branch = $input['branch'] ?? 'main';
    $job = $input['job'] ?? 'some';
    $job_id = $input['job_id'] ?? null;

    // no null some of the vars
    if (! $projectId || ! $job_id) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Missing required fields: project_id and job_id']);
        exit();
    }

    return new ArifactContract(
        project_id: $projectId,
        branch: $branch,
        job: $job,
        job_id: $job_id,
    );
}

// --- LOGIC ---
// ================================================================================

function getRequestHeadersSafe()
{
    if (function_exists('getallheaders')) {
        return getallheaders();
    }

    $headers = [];
    foreach ($_SERVER as $key => $value) {
        if (strpos($key, 'HTTP_') === 0) {
            $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
            $headers[$name] = $value;
        }
    }

    foreach (['CONTENT_TYPE', 'CONTENT_LENGTH', 'CONTENT_MD5', 'AUTHORIZATION'] as $key) {
        if (isset($_SERVER[$key])) {
            $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', $key))));
            $headers[$name] = $_SERVER[$key];
        }
    }

    return $headers;
}

function logRequestToFile($logFilePath, $rawBody)
{
    $decodedBody = json_decode($rawBody, true);
    $entry = [
        'timestamp' => date('c'),
        'method' => $_SERVER['REQUEST_METHOD'] ?? 'CLI',
        'uri' => $_SERVER['REQUEST_URI'] ?? null,
        'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? null,
        'query_params' => $_GET,
        'post_params' => $_POST,
        'json_body' => json_last_error() === JSON_ERROR_NONE ? $decodedBody : null,
        'raw_body' => $rawBody,
        'headers' => getRequestHeadersSafe(),
    ];

    file_put_contents(
        $logFilePath,
        json_encode($entry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL.str_repeat('=', 80).PHP_EOL,
        FILE_APPEND,
    );
}

function validateSecurity()
{
    global $config;
    if (empty($config['secure_token']))
        return;
    if (isset($_GET['manual']) && $_GET['manual'] === '1')
        return;

    // Allow requests from localhost/127.0.0.1 (UI requests) without token
    $clientIP = $_SERVER['REMOTE_ADDR'] ?? '';
    if ($clientIP === 'localhost' || $clientIP === '127.0.0.1' || $clientIP === '::1') {
        return;
    }

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
    // Print in console debug
    $cmd = escapeshellcmd("$yqPath -o json '$ymlPath'");
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
    if (
        strtolower(pathinfo($instructionsFile, PATHINFO_EXTENSION)) === 'yml'
        || strtolower(pathinfo($instructionsFile, PATHINFO_EXTENSION)) === 'yaml'
    ) {
        $yqPath = env('YQ_PATH');
        if (! $yqPath) {
            return 'YQ_PATH environment variable is not set. Required for processing YAML files.';
        }

        // Check if yq binary exists
        if (! file_exists($yqPath)) {
            return "YQ binary not found at path: $yqPath";
        }

        // Check if it's a regular file
        if (! is_file($yqPath)) {
            return "YQ path is not a regular file: $yqPath";
        }

        // Check if it's executable, if not try to fix it
        if (! is_executable($yqPath)) {
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
    $logFilePathFRaw = $config['logs_path'].'/'.$logFileName.'.fraw';

    // Get tasks in the instructions file
    $jsonContent = getInstructionsContent();
    $tasks = json_decode($jsonContent, true);

    runTasks($logFilePath, $logFilePathRaw, $logFIlePathHTML, $logFilePathFRaw, $tasks);
}

function executeArtifactDeployment(?string $projectId, string $job, string $job_id, string $branch = 'main')
{
    global $config, $statusFile;

    if (empty($projectId) || empty($job_id)) {
        http_response_code(400);
        exit('Missing params');
    }

    // Check for concurrent deployment
    if (file_exists($statusFile)) {
        $current = json_decode(file_get_contents($statusFile), true);
        if (isset($current['finished']) && $current['finished'] === true) {
            unlink($statusFile);
        } else {
            http_response_code(409);
            exit('Deployment already in progress.');
        }
    }

    // Generate log filenames immediately so we can log the download/extract phases
    $logFileName = 'artifact_deploy_'.date('Ymd_His').'.log';
    $logFilePath = $config['logs_path'].'/'.$logFileName;
    $logFilePathRaw = $config['logs_path'].'/'.$logFileName.'.rlog';
    $logFilePathHTML = $config['logs_path'].'/'.$logFileName.'.html';
    $logFilePathFRaw = $config['logs_path'].'/'.$logFileName.'.fraw';

    $startTime = microtime(true);
    $host = defined('CLI_HOST') ? CLI_HOST : $_SERVER['HTTP_HOST'] ?? 'localhost';

    // Lock the status file IMMEDIATELY to prevent race conditions during download/extract.
    // Any concurrent request will see this and get a 409 before the download even starts.
    file_put_contents($statusFile, json_encode([
        'running' => true,
        'finished' => false,
        'task' => 'Downloading artifact...',
        'index' => 0,
        'total' => 0,
        'pid' => getmypid(),
        'log_file' => $logFileName,
        'start' => $startTime,
    ]));

    // Initialize fraw log for pre-run phases (download, extract)
    $preLog = 'START: '.date('Y-m-d H:i:s')."\n";
    $preLogRaw = 'START: '.date('Y-m-d H:i:s')."\n";
    file_put_contents($logFilePathFRaw, $preLog);

    // Helper: write a line to all pre-run logs
    $logLine = function (string $line, string $level = 'info') use (&$preLog, &$preLogRaw, $logFilePathFRaw) {
        $ts = date('Y-m-d H:i:s');
        $preLog .= "[PRE][$level] $line\n";
        $preLogRaw .= "[$ts][$level] $line\n";
        file_put_contents($logFilePathFRaw, "[PRE][$level] $line\n", FILE_APPEND);
    };

    // Helper: fail during pre-run phase — writes logs, updates status, notifies, exits
    $failArtifact = function (string $msg, string $task = '') use (
        &$preLog,
        &$preLogRaw,
        $logFilePath,
        $logFilePathRaw,
        $logFilePathHTML,
        $logFilePathFRaw,
        $statusFile,
        $host,
        $startTime,
        $logFileName,
    ) {
        $duration = round(microtime(true) - $startTime, 2);
        $protocol = isset($_SERVER['HTTPS']) ? 'https://' : 'http://';
        $logId = pathinfo($logFilePath, PATHINFO_FILENAME);
        $logUrl = "$protocol{$host}/log/rview/{$logId}";

        file_put_contents($logFilePath, $preLog."\nERROR: $msg\nEND. Duration: {$duration}s");
        file_put_contents($logFilePathRaw, $preLogRaw."\nERROR: $msg\nEND. Duration: {$duration}s");
        file_put_contents($logFilePathFRaw, "[PRE][error] $msg\nEND. Duration: {$duration}s\n", FILE_APPEND);
        file_put_contents($logFilePathHTML, createLogHtml(
            'Artifact Deploy Log',
            '<h1>Artifact Deploy Log</h1><p style="color:red;font-weight:bold;">ERROR: '
            .htmlspecialchars($msg)
            .'</p><p>Duration: '
            .$duration
            .'s</p>',
        ));
        file_put_contents($statusFile, json_encode([
            'running' => false,
            'finished' => true,
            'success' => false,
            'task' => "FAILED: $msg",
            'duration' => $duration,
            'log_file' => $logFileName,
        ]));
        sendTelegram(buildReport($host, false, $duration, $logUrl, $task ?: 'Artifact Deploy', $msg));
        http_response_code(500);
        exit($msg);
    };

    // Validate required config
    $gitlabToken = $config['gitlab_token'];
    if (empty($gitlabToken)) {
        $logLine('GITLAB_TOKEN not configured', 'error');
        $failArtifact('GITLAB_TOKEN not configured');
    }

    $instructionsFile = $config['artifact_instructions'];
    if (! file_exists($instructionsFile)) {
        $msg = "Artifact instructions file not found: $instructionsFile";
        $logLine($msg, 'error');
        $failArtifact($msg);
    }

    // Prepare artifact deploy directory
    $deployDir = $config['artifact_deploy_dir'];
    if (! is_dir($deployDir)) {
        mkdir($deployDir, 0755, true);
    }

    // Download artifact from GitLab
    $artifactFile = $deployDir.'/artifact.zip';
    $gitlabBase = rtrim($config['gitlab_base_url'], '/');
    //$artifactUrl = "{$gitlabBase}/api/v4/projects/{$projectId}/jobs/artifacts/{$branch}/download?job={$job}";
    $artifactUrl = "{$gitlabBase}/api/v4/projects/{$projectId}/jobs/{$job_id}/artifacts";

    $logLine("Downloading artifact — project: $projectId | branch: $branch | job: $job");
    $logLine("URL: $artifactUrl");

    $fp = fopen($artifactFile, 'w');
    if (! $fp) {
        $msg = "Cannot write artifact file to: $artifactFile";
        $logLine($msg, 'error');
        $failArtifact($msg);
    }

    $ch = curl_init($artifactUrl);
    curl_setopt_array($ch, [
        CURLOPT_FILE => $fp,
        CURLOPT_HTTPHEADER => ["PRIVATE-TOKEN: $gitlabToken"],
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_FAILONERROR => true,
    ]);
    $result = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    // DEPRECATED:
    // curl_close($ch);
    fclose($fp);

    if ($result === false) {
        $msg = "Artifact download failed (HTTP $httpCode): $curlError";
        $logLine($msg, 'error');
        $failArtifact($msg, 'Download');
    }

    $fileSize = round(filesize($artifactFile) / 1024, 1);
    $logLine("Artifact downloaded successfully — {$fileSize} KB (HTTP $httpCode)");

    // Update status: extracting
    file_put_contents($statusFile, json_encode([
        'running' => true,
        'finished' => false,
        'task' => 'Extracting artifact...',
        'index' => 0,
        'total' => 0,
        'pid' => getmypid(),
        'log_file' => $logFileName,
        'start' => $startTime,
    ]));

    // Extract artifact
    $extractDir = $deployDir.'/extracted';
    if (is_dir($extractDir)) {
        exec('rm -rf '.escapeshellarg($extractDir));
    }
    mkdir($extractDir, 0755, true);

    $logLine("Extracting artifact to: $extractDir");

    if (! extractArtifact($artifactFile, $extractDir)) {
        $logLine('Failed to extract artifact', 'error');
        $failArtifact('Failed to extract artifact', 'Extract');
    }

    $logLine('Artifact extracted successfully');

    // Validate and load instructions
    $jsonContent = file_get_contents($instructionsFile);
    $tasks = json_decode($jsonContent, true);
    if (is_null($tasks)) {
        $msg = 'Invalid JSON in artifact instructions file: '.json_last_error_msg();
        $logLine($msg, 'error');
        $failArtifact($msg);
    }

    $errInstructions = validateInstructions($tasks);
    if ($errInstructions !== true) {
        $msg = "Invalid artifact task instructions: $errInstructions";
        $logLine($msg, 'error');
        $failArtifact($msg);
    }

    $logLine('Instructions validated — '.count($tasks).' task(s) queued');
    $logLine("Starting task execution in: $extractDir");

    // Run tasks using the extracted directory as CWD.
    // Pass pre-run log content so it is included in the final .log and .rlog files.
    runTasks(
        $logFilePath,
        $logFilePathRaw,
        $logFilePathHTML,
        $logFilePathFRaw,
        $tasks,
        $extractDir,
        $preLog,
        $preLogRaw,
    );
}

function extractArtifact(string $file, string $destDir): bool
{
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));

    // ZIP: use PHP's built-in ZipArchive
    if ($ext === 'zip') {
        $zip = new ZipArchive;
        if ($zip->open($file) === true) {
            $zip->extractTo($destDir);
            $zip->close();

            return true;
        }

        return false;
    }

    // RAR: try unrar, then fall through to 7z
    if ($ext === 'rar') {
        exec('unrar x -o+ '.escapeshellarg($file).' '.escapeshellarg($destDir).'/', $out, $code);
        if ($code === 0)
            return true;
    }

    // Universal fallback: 7z (supports zip, rar, tar, gz, bz2, xz, etc.)
    exec('7z x '.escapeshellarg($file).' -o'.escapeshellarg($destDir).' -y', $out, $code);

    return $code === 0;
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
    string $logFilePathFRaw,
    array $tasks,
    ?string $cwd = null,
    string $logPrefix = '',
    string $logRawPrefix = '',
) {
    global $statusFile, $config;

    // Setup log files
    $startTime = microtime(true);

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

    $cmdsCWD = $cwd ?? $config['project_path'];

    // initialize the flags
    $success = true;
    $failedTask = '';
    // Prepend any pre-run log content (e.g. from artifact download/extract phase)
    $fullLog = $logPrefix.'START: '.date('Y-m-d H:i:s')."\n";
    $fullLogRaw = $logRawPrefix.'START: '.date('Y-m-d H:i:s')."\n";
    $fullLogFRaw = 'START TASKS: '.date('Y-m-d H:i:s')."\n";
    $htmlLogContent = '<h1>Deployment Log - Started at '.date('Y-m-d H:i:s')."</h1>\n";
    // Use FILE_APPEND so pre-run content written to fraw is preserved
    file_put_contents($logFilePathFRaw, $fullLogFRaw, FILE_APPEND);

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
        $fullLogFRaw .= "\n+---------------------------------------------+\n";
        $htmlLogContent .= '<hr>';
        file_put_contents($logFilePathFRaw, "\n+---------------------------------------------+\n", FILE_APPEND);
        $fullLog .= "[TASK]: $taskToRunName";
        $fullLogFRaw .= "[TASK]: $taskToRunName\n";
        $htmlLogContent .= "<h2>$taskToRunName</h2>\n";
        file_put_contents($logFilePathFRaw, "[TASK]: $taskToRunName\n", FILE_APPEND);

        $taskSuccess = true;
        $errorOutput = '';

        foreach ($commandsToRun as $cmd) {
            $fullLog .= "\n[CMD]: $cmd\n";
            $fullLogRaw .= '['.date('Y-m-d H:i:s')."] $cmd\n";
            $fullLogFRaw .= "\n[CMD]: $cmd\n";
            file_put_contents($logFilePathFRaw, "\n[CMD]: $cmd\n", FILE_APPEND);
            $cmdHtml = explode('\\n', $cmd);
            $cmdHtml = implode('<br>', array_map('htmlspecialchars', $cmdHtml));
            $htmlLogContent .= "<h3>Command: $cmdHtml</h3>\n";

            // Wrap command with strict error handling
            // Use trap to ensure exit code is always captured, even on errors
            // Single quotes in trap to prevent variable expansion issues

            $wrapped = sprintf(
                "{\n%s\n}\n__exit__=$?\necho '__STDOUT_EOF__'\"\$__exit__\"\necho '__STDERR_EOF__'\"\$__exit__\" >&2\n",
                $cmd,
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
                $status = proc_get_status($process);

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

                if ($status['running'] === false && $streamResult > 0) {
                    // Process exited but we still have streams to read
                    // We'll continue to read until we get EOF markers
                    $stderr .= 'Shell CLosed';
                    $commandsToRun = []; // Stop sending more commands
                    break;
                }

                $hasOutput = false;
                foreach ($read as $stream) {
                    $line = fgets($stream);
                    if ($line === false)
                        continue;

                    $hasOutput = true;
                    if ($stream === $pipes[1]) {
                        // Match __STDOUT_EOF__123 format
                        $trimedLine = trim($line);
                        if (
                            preg_match('/^__STDOUT_EOF__(.*)$/', $trimedLine, $m)
                            || str_contains($trimedLine, '__STDOUT_EOF__') // NOTE: Is possible a error if the element contains problems
                        ) {
                            $exitCode = (int) $m[1];
                            $stdoutDone = true;
                            $fullLogFRaw .= '[STDOUT] '.trim($line)."\n";
                            file_put_contents($logFilePathFRaw, '[STDOUT] '.trim($line)."\n", FILE_APPEND);
                        } else {
                            $stdout .= $line;
                            $statusData['current_output'] .= $line;
                            $taskStatus[$indx]['output'] .= $line;
                            $fullLogRaw .= '['.date('Y-m-d H:i:s')."][info  ] $line";
                            $fullLogFRaw .= '[STDOUT] '.$line;
                            file_put_contents($logFilePathFRaw, '[STDOUT] '.$line, FILE_APPEND);
                            $htmlLogContent .= htmlspecialchars($line);
                        }
                    } else {
                        // Match __STDERR_EOF__123 format
                        if (preg_match('/^__STDERR_EOF__(.*)$/', trim($line), $m)) {
                            $stderrDone = true;
                            $fullLogFRaw .= '[STDERR] '.trim($line)."\n";
                            file_put_contents($logFilePathFRaw, '[STDERR] '.trim($line)."\n", FILE_APPEND);
                        } else {
                            $stderr .= $line;
                            $statusData['current_output'] .= $line;
                            $taskStatus[$indx]['output'] .= $line;
                            $fullLogRaw .= '['.date('Y-m-d H:i:s')."][error ] $line";
                            $fullLogFRaw .= '[STDERR] '.$line;
                            file_put_contents($logFilePathFRaw, '[STDERR] '.$line, FILE_APPEND);
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

            // Check for command failure - either non-zero exit code or error patterns in stderr
            $hasErrorPatterns = preg_match('/(npm error|error:|failed|Error:|ERR!)/i', $stderr);

            if ($exitCode !== 0 || $hasErrorPatterns) {
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
    $fullLogFRaw .= "\n=============================================\n";
    file_put_contents($logFilePathFRaw, "\n=============================================\n", FILE_APPEND);

    $duration = round(microtime(true) - $startTime, 2);

    $htmlLogContent .= "<h2>Deployment finished in {$duration}s</h2>\n";
    $htmlLogContent .= '<p><strong>Overall Status: '.($success ? 'SUCCESS' : "FAILED at $failedTask")."</strong></p>\n";

    // Update logs after each task
    file_put_contents($lofgilePath, $fullLog."\nEND. Duration: {$duration}s");
    file_put_contents($logFilePathRaw, $fullLogRaw."\nEND. Duration: {$duration}s");
    file_put_contents($logFilePathFRaw, "\nEND. Duration: {$duration}s", FILE_APPEND);
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

function showLastLogFRaw()
{
    global $config;
    $logs = glob($config['logs_path'].'/*.fraw');
    if (! $logs)
        exit('No fraw logs available.');
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

function runTasksWithShell($tasks, &$fullLog, &$fullLogRaw, &$taskStatus)
{
    $descriptors = [
        0 => ['pipe', 'r'], // stdin
        1 => ['pipe', 'w'], // stdout
        2 => ['pipe', 'w'], // stderr
    ];

    $process = proc_open('/bin/bash -s', $descriptors, $pipes);

    if (! is_resource($process)) {
        throw new RuntimeException('Unable to start shell');
    }

    foreach ($tasks as $index => $task) {
        $name = $task['name'] ?? 'Unnamed Task';
        $cmd = $task['run'];

        $taskStatus[$index]['status'] = 'running';

        $fullLog .= "\n[TASK]: $name\n[CMD]: $cmd\n";
        $fullLogRaw .= '['.date('Y-m-d H:i:s')."] $cmd\n";

        fwrite($pipes[0], $cmd."\n");
        fwrite($pipes[0], "echo __EXIT_CODE:$?\n");

        fflush($pipes[0]);

        $stdout = '';
        $stderr = '';
        $exitCode = 1;

        while (true) {
            $line = fgets($pipes[1]);

            if ($line === false)
                break;

            if (str_starts_with($line, '__EXIT_CODE:')) {
                $exitCode = (int) trim(str_replace('__EXIT_CODE:', '', $line));
                break;
            }

            $stdout .= $line;
        }

        echo 'DONE';
        $stderr .= stream_get_contents($pipes[2]);
        echo '<br>';

        $fullLog .= "STDOUT: $stdout\nSTDERR: $stderr\nEXIT: $exitCode\n";
        $fullLogRaw .= $stdout;

        if ($exitCode === 0) {
            $taskStatus[$index]['status'] = 'success';
        } else {
            $taskStatus[$index]['status'] = 'failed';

            // return [$exitCode, $name, $stderr ?: $stdout];
            return [$exitCode, $name ?? '', $stderr ?: $stdout, $index ?? 0];
        }
        echo '====';
    }

    fclose($pipes[0]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    proc_close($process);

    return [0, '', '', $index ?? 0];
}

// --- VIEWS ---
// ================================================================================

function renderValidationError($errorMessage)
{ ?>
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
<?php }

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

function appBaseUrl()
{
    $host = $_SERVER['HTTP_HOST'] ?? (defined('CLI_HOST') ? CLI_HOST : 'localhost');
    $isHttps =
        (! empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (string) $_SERVER['SERVER_PORT'] === '443');

    return ($isHttps ? 'https://' : 'http://').$host;
}

function resolveLogExecutionStatus($logPath)
{
    if (! file_exists($logPath)) {
        return null;
    }

    $content = file_get_contents($logPath);
    if ($content === false || trim($content) === '') {
        return null;
    }

    $lines = preg_split('/\r\n|\r|\n/', trim($content));
    $exitLine = $lines[count($lines) - 5] ?? end($lines) ?? '';
    if ($exitLine === '') {
        return null;
    }

    return str_contains($exitLine, 'EXIT: 0');
}

function formatBytesLabel($size)
{
    if ($size < 1024) {
        return $size.' B';
    }

    if ($size < 1048576) {
        return round($size / 1024, 1).' KB';
    }

    return round($size / 1048576, 2).' MB';
}

function renderHealthView()
{
    global $config, $statusFile, $defaults;

    $serverIp = $_SERVER['SERVER_ADDR'] ?? 'Local';
    $serverDomain = $_SERVER['HTTP_HOST'] ?? 'Unknown Domain';
    $phpVersion = PHP_VERSION;
    $instructionExists = file_exists($config['instructions']);
    $artifactInstructionExists = file_exists($config['artifact_instructions']);
    $logs = glob($config['logs_path'].'/*.log') ?: [];
    usort($logs, fn ($a, $b) => filemtime($b) - filemtime($a));
    $lastLogs = array_slice($logs, 0, 3);

    $statusData = file_exists($statusFile) ? json_decode(file_get_contents($statusFile), true) : null;
    $isActuallyRunning = $statusData && (! isset($statusData['finished']) || ! $statusData['finished']);
    $baseUrl = appBaseUrl();
    $deployWebhookUrl = $baseUrl.'/webhook/deploy';
    $artifactWebhookUrl = $baseUrl.'/webhook/artifact-deploy';
    $logsCount = count($logs);
    $lastLogStatus = $logs ? resolveLogExecutionStatus($logs[0]) : null;
    $lastLogAt = $logs ? date('Y-m-d H:i:s', filemtime($logs[0])) : 'No runs yet';
    $systemReady = $instructionExists && ! empty($config['project_path']) && ! empty($config['logs_path']);
    $artifactReady =
        $artifactInstructionExists
        && ! empty($config['gitlab_token'])
        && ! empty($config['artifact_deploy_dir'])
        && ! empty($config['gitlab_base_url']);
    $securityEnabled = ! empty($config['secure_token']);
    $headlineStatusLabel = $isActuallyRunning
        ? 'Deployment running'
        : ($lastLogStatus === true
            ? 'System healthy'
            : ($lastLogStatus === false ? 'Needs attention' : 'Ready for deploy'));
    $headlineStatusClasses = $isActuallyRunning
        ? 'border-blue-500/30 bg-blue-500/10 text-blue-600 dark:text-blue-300'
        : ($lastLogStatus === true
            ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-600 dark:text-emerald-300'
            : ($lastLogStatus === false
                ? 'border-amber-500/30 bg-amber-500/10 text-amber-600 dark:text-amber-300'
                : 'border-slate-300 bg-white text-slate-600 dark:border-slate-700 dark:bg-slate-900/40 dark:text-slate-300'));

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

            async function copyToClipboard(button, value) {
                const originalText = button.dataset.originalText || button.textContent;

                try {
                    if (navigator.clipboard && window.isSecureContext) {
                        await navigator.clipboard.writeText(value);
                    } else {
                        const input = document.createElement('textarea');
                        input.value = value;
                        input.setAttribute('readonly', '');
                        input.style.position = 'absolute';
                        input.style.left = '-9999px';
                        document.body.appendChild(input);
                        input.select();
                        document.execCommand('copy');
                        document.body.removeChild(input);
                    }

                    button.dataset.originalText = originalText;
                    button.textContent = 'Copied';
                    button.classList.add('bg-emerald-500', 'text-white', 'border-emerald-500');

                    setTimeout(() => {
                        button.textContent = originalText;
                        button.classList.remove('bg-emerald-500', 'text-white', 'border-emerald-500');
                    }, 1600);
                } catch (error) {
                    window.prompt('Copy this value:', value);
                }
            }
        </script>
    </head>

    <body class="bg-[#f8fafc] dark:bg-[#0b0f1a] text-slate-600 dark:text-slate-300 p-8 font-mono text-sm transition-colors duration-200">
        <div class="max-w-7xl mx-auto space-y-8">
            <div class="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white/90 dark:bg-[#161b2a]/95 shadow-sm dark:shadow-xl overflow-hidden">
                <div class="px-6 py-6 lg:px-8 lg:py-7 flex flex-col gap-6 lg:flex-row lg:items-start lg:justify-between">
                    <div class="space-y-4">
                        <div class="inline-flex items-center gap-2 rounded-full border px-3 py-1 text-[11px] font-bold uppercase tracking-[0.2em] <?= $headlineStatusClasses ?>">
                            <span class="inline-flex h-2 w-2 rounded-full <?= $isActuallyRunning
                                ? 'bg-blue-500 animate-pulse'
                                : ($lastLogStatus === true
                                    ? 'bg-emerald-500'
                                    : ($lastLogStatus === false ? 'bg-amber-500' : 'bg-slate-400 dark:bg-slate-500')) ?>"></span>
                            <?= htmlspecialchars($headlineStatusLabel) ?>
                        </div>

                        <div>
                            <h1 class="text-slate-900 dark:text-white font-bold text-2xl tracking-tight uppercase">Simple PHP <span class="text-[#a855f7]">Deployer</span></h1>
                            <p class="mt-2 max-w-2xl text-sm text-slate-500 dark:text-slate-400">
                                Dashboard operativo para ejecuciones manuales, webhooks y revisi&oacute;n r&aacute;pida de actividad reciente.
                            </p>
                        </div>

                        <div class="flex flex-wrap gap-2 text-[11px]">
                            <span class="rounded-full border border-slate-200 dark:border-slate-700 px-3 py-1 text-slate-500 dark:text-slate-300">Host: <span class="text-slate-700 dark:text-slate-100"><?= htmlspecialchars(
                                $serverDomain,
                            ) ?></span></span>
                            <span class="rounded-full border border-slate-200 dark:border-slate-700 px-3 py-1 text-slate-500 dark:text-slate-300">IP: <span class="text-slate-700 dark:text-slate-100"><?= htmlspecialchars(
                                $serverIp,
                            ) ?></span></span>
                            <span class="rounded-full border border-slate-200 dark:border-slate-700 px-3 py-1 text-slate-500 dark:text-slate-300">PHP <?= htmlspecialchars(
                                $phpVersion,
                            ) ?></span>
                            <span class="rounded-full border border-slate-200 dark:border-slate-700 px-3 py-1 text-slate-500 dark:text-slate-300">Webhook <?= htmlspecialchars(
                                $config['webhook_method'],
                            ) ?></span>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-3 lg:min-w-[320px]">
                        <div class="rounded-xl border border-slate-200 dark:border-slate-800 bg-slate-50/80 dark:bg-slate-900/40 p-4">
                            <div class="text-[10px] uppercase tracking-[0.2em] text-slate-400 dark:text-slate-500">Logs</div>
                            <div class="mt-2 text-2xl font-bold text-slate-900 dark:text-white"><?= $logsCount ?></div>
                            <div class="mt-1 text-[11px] text-slate-500 dark:text-slate-400">Historial total disponible</div>
                        </div>
                        <div class="rounded-xl border border-slate-200 dark:border-slate-800 bg-slate-50/80 dark:bg-slate-900/40 p-4">
                            <div class="text-[10px] uppercase tracking-[0.2em] text-slate-400 dark:text-slate-500">Last result</div>
                            <div class="mt-2 text-sm font-bold <?= $lastLogStatus === true
                                ? 'text-emerald-600 dark:text-emerald-400'
                                : ($lastLogStatus === false
                                    ? 'text-amber-600 dark:text-amber-400'
                                    : 'text-slate-900 dark:text-white') ?>">
                                <?= $lastLogStatus === true
                                    ? 'Success'
                                    : ($lastLogStatus === false ? 'Failed' : 'No executions') ?>
                            </div>
                            <div class="mt-1 text-[11px] text-slate-500 dark:text-slate-400"><?= htmlspecialchars(
                                $lastLogAt,
                            ) ?></div>
                        </div>
                        <div class="rounded-xl border border-slate-200 dark:border-slate-800 bg-slate-50/80 dark:bg-slate-900/40 p-4">
                            <div class="text-[10px] uppercase tracking-[0.2em] text-slate-400 dark:text-slate-500">Deploy setup</div>
                            <div class="mt-2 text-sm font-bold <?= $systemReady
                                ? 'text-emerald-600 dark:text-emerald-400'
                                : 'text-rose-600 dark:text-rose-400' ?>">
                                <?= $systemReady ? 'Ready' : 'Review required' ?>
                            </div>
                            <div class="mt-1 text-[11px] text-slate-500 dark:text-slate-400"><?= htmlspecialchars(
                                basename($config['instructions']),
                            ) ?></div>
                        </div>
                        <div class="rounded-xl border border-slate-200 dark:border-slate-800 bg-slate-50/80 dark:bg-slate-900/40 p-4">
                            <div class="text-[10px] uppercase tracking-[0.2em] text-slate-400 dark:text-slate-500">Artifact setup</div>
                            <div class="mt-2 text-sm font-bold <?= $artifactReady
                                ? 'text-emerald-600 dark:text-emerald-400'
                                : 'text-rose-600 dark:text-rose-400' ?>">
                                <?= $artifactReady ? 'Ready' : 'Review required' ?>
                            </div>
                            <div class="mt-1 text-[11px] text-slate-500 dark:text-slate-400">
                                <?= htmlspecialchars(basename($config['artifact_instructions'])) ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <?php if (! $instructionExists): ?>
                <div class="bg-amber-100 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-500/50 p-4 rounded mb-8 text-amber-700 dark:text-amber-200">
                    <span class="font-bold">ALERT:</span> Instruction file not found at "<?= $config['instructions'] ?>".
                </div>
            <?php endif; ?>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <div class="lg:col-span-2 space-y-6">
                    <div class="bg-white dark:bg-[#161b2a] border border-slate-200 dark:border-slate-800 rounded-2xl shadow-sm dark:shadow-xl overflow-hidden">
                        <div class="px-6 py-5 border-b border-slate-100 dark:border-slate-800 bg-slate-50/70 dark:bg-slate-900/40 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <h2 class="text-slate-900 dark:text-white text-sm font-bold uppercase tracking-[0.2em]">Webhook endpoints</h2>
                                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Copia las URLs p&uacute;blicas para integraciones de despliegue y artifacts.</p>
                            </div>
                            <?php if ($securityEnabled): ?>
                                <div class="text-[11px] text-slate-500 dark:text-slate-400">
                                    Auth: <span class="text-slate-800 dark:text-slate-100 font-bold">X-Deploy-Token</span>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="grid grid-cols-1 xl:grid-cols-2 gap-4 p-6">
                            <?php
                            $webhookCards = [
                                [
                                    'title' => 'Call Deploy',
                                    'description' => 'Webhook principal para ejecutar el despliegue est&aacute;ndar.',
                                    'url' => $deployWebhookUrl,
                                    'path' => '/webhook/deploy',
                                ],
                                [
                                    'title' => 'Artifact Deploy',
                                    'description' => 'Webhook para descargar, extraer y desplegar artifacts de GitLab.',
                                    'url' => $artifactWebhookUrl,
                                    'path' => '/webhook/artifact-deploy',
                                ],
                            ];

                            foreach ($webhookCards as $card): ?>
                                <div class="rounded-xl border border-slate-200 dark:border-slate-800 bg-slate-50/60 dark:bg-slate-900/30 p-5 space-y-4">
                                    <div class="flex items-start justify-between gap-3">
                                        <div>
                                            <div class="text-sm font-bold text-slate-900 dark:text-white"><?= $card['title'] ?></div>
                                            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400"><?= $card['description'] ?></p>
                                        </div>
                                        <span class="rounded-full border border-slate-200 dark:border-slate-700 px-2.5 py-1 text-[10px] font-bold uppercase tracking-[0.2em] text-slate-500 dark:text-slate-300"><?= htmlspecialchars(
                                            $config['webhook_method'],
                                        ) ?></span>
                                    </div>

                                    <div class="rounded-lg border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-950/60 p-3">
                                        <div class="text-[10px] uppercase tracking-[0.2em] text-slate-400 dark:text-slate-500">URL</div>
                                        <div class="mt-2 break-all text-xs text-slate-700 dark:text-slate-200"><?= htmlspecialchars(
                                            $card['url'],
                                        ) ?></div>
                                    </div>

                                    <div class="flex flex-wrap items-center gap-2">
                                        <button type="button"
                                            onclick='copyToClipboard(this, <?= json_encode($card['url']) ?>)'
                                            class="rounded-lg border border-slate-300 dark:border-slate-700 px-3 py-2 text-[11px] font-bold uppercase tracking-[0.18em] text-slate-700 dark:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800 transition">
                                            Copy URL
                                        </button>
                                        <span class="text-[11px] text-slate-500 dark:text-slate-400">Path: <span class="text-slate-700 dark:text-slate-200"><?= htmlspecialchars(
                                            $card['path'],
                                        ) ?></span></span>
                                    </div>

                                    <?php if ($securityEnabled): ?>
                                        <p class="text-[11px] text-slate-500 dark:text-slate-400">
                                            Si el token est&aacute; activo, env&iacute;a <span class="font-bold text-slate-700 dark:text-slate-100">X-Deploy-Token</span>
                                            o agrega <span class="font-bold text-slate-700 dark:text-slate-100">?token=...</span>.
                                        </p>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-[#161b2a] border border-slate-200 dark:border-slate-800 rounded-2xl shadow-sm dark:shadow-xl overflow-hidden">
                        <div class="px-6 py-5 border-b border-slate-100 dark:border-slate-800 bg-slate-50/70 dark:bg-slate-900/40 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <h2 class="text-slate-900 dark:text-white text-sm font-bold uppercase tracking-[0.2em]">Recent execution history</h2>
                                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Mostrando los 3 &uacute;ltimos logs. El historial completo sigue disponible en la vista dedicada.</p>
                            </div>
                            <a href="/alllogs" class="text-[11px] text-indigo-600 dark:text-indigo-400 hover:underline font-bold uppercase tracking-[0.18em] transition">View all logs</a>
                        </div>

                        <div class="p-6 space-y-4">
                            <?php foreach ($lastLogs as $logPath):
                                $fn = basename($logPath);
                                $logName = pathinfo($fn, PATHINFO_FILENAME);
                                $isOk = resolveLogExecutionStatus($logPath);
                                $statusLabel = $isOk === true ? 'Success' : ($isOk === false ? 'Failed' : 'Unknown');
                                $statusClasses = $isOk === true
                                    ? 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400'
                                    : ($isOk === false
                                        ? 'bg-rose-500/10 text-rose-600 dark:text-rose-400'
                                        : 'bg-slate-500/10 text-slate-600 dark:text-slate-400');
                                ?>
                                <div class="rounded-xl border border-slate-200 dark:border-slate-800 bg-slate-50/60 dark:bg-slate-900/30 p-5">
                                    <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                                        <div class="space-y-2 min-w-0">
                                            <div class="flex flex-wrap items-center gap-2">
                                                <span class="rounded-full px-2.5 py-1 text-[10px] font-bold uppercase tracking-[0.18em] <?= $statusClasses ?>"><?= $statusLabel ?></span>
                                                <span class="text-[11px] text-slate-500 dark:text-slate-400"><?= date(
                                                    'Y-m-d H:i:s',
                                                    filemtime($logPath),
                                                ) ?></span>
                                                <span class="text-[11px] text-slate-400 dark:text-slate-500">&bull;</span>
                                                <span class="text-[11px] text-slate-500 dark:text-slate-400"><?= formatBytesLabel(
                                                    filesize($logPath),
                                                ) ?></span>
                                            </div>
                                            <div class="truncate text-sm font-bold text-slate-900 dark:text-white"><?= htmlspecialchars(
                                                $fn,
                                            ) ?></div>
                                        </div>

                                        <div class="flex flex-wrap items-center gap-2">
                                            <a target="_blank" href="/log/rview/<?= urlencode($logName) ?>" class="rounded-lg border border-slate-300 dark:border-slate-700 px-3 py-2 text-[11px] font-bold uppercase tracking-[0.18em] text-slate-700 dark:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800 transition">Open</a>
                                            <a target="_blank" href="/log/bview/<?= urlencode($logName) ?>" class="rounded-lg border border-slate-300 dark:border-slate-700 px-3 py-2 text-[11px] font-bold uppercase tracking-[0.18em] text-slate-700 dark:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800 transition">Raw</a>
                                            <a target="_blank" href="/log/htmlview/<?= urlencode($logName) ?>" class="rounded-lg border border-slate-300 dark:border-slate-700 px-3 py-2 text-[11px] font-bold uppercase tracking-[0.18em] text-slate-700 dark:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800 transition">HTML</a>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach;
                            if (! $lastLogs): ?>
                                <div class="rounded-xl border border-dashed border-slate-300 dark:border-slate-700 p-10 text-center text-sm text-slate-400 dark:text-slate-500">
                                    No execution history found.
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="px-6 py-4 border-t border-slate-100 dark:border-slate-800 bg-slate-50/70 dark:bg-slate-900/40 flex flex-col gap-2 text-[11px] text-slate-500 dark:text-slate-400 sm:flex-row sm:items-center sm:justify-between">
                            <span><?= $logsCount ?> log file(s) available</span>
                            <span>Use <span class="font-bold text-slate-700 dark:text-slate-200">/alllogs</span> for full browsing.</span>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-[#161b2a] border border-slate-200 dark:border-slate-800 p-6 rounded-2xl shadow-sm dark:shadow-xl">
                        <h2 class="text-slate-900 dark:text-white text-sm font-bold mb-4 uppercase tracking-[0.2em]">Example deploy.json</h2>
                        <pre class="text-[11px] text-indigo-700 dark:text-indigo-300 overflow-x-auto bg-slate-50 dark:bg-slate-950 p-4 rounded-xl border border-slate-100 dark:border-slate-800">[
  { "name": "Git Pull", "run": "git pull origin main" },
  { "name": "Install Dependencies", "run": "composer install --no-dev" },
  { "name": "Optimize Cache", "run": "php artisan config:cache" }
]</pre>
                    </div>
                </div>

                <div class="space-y-6">
                    <div class="bg-white dark:bg-[#161b2a] border border-slate-200 dark:border-slate-800 p-5 rounded-2xl shadow-sm dark:shadow-xl">
                        <h2 class="text-slate-900 dark:text-white text-sm font-bold mb-4 uppercase tracking-[0.2em]">Quick actions</h2>
                        <div class="space-y-2">
                            <?php if ($isActuallyRunning): ?>
                                <a href="/status/live" class="flex items-center justify-center gap-2 w-full bg-blue-600 text-white py-3 rounded-xl text-xs font-bold uppercase tracking-[0.18em] shadow-lg shadow-blue-500/20 animate-pulse">
                                    <span class="inline-block w-2 h-2 bg-white rounded-full"></span>
                                    Process running
                                </a>
                            <?php else: ?>
                                <a href="/webhook/deploy?manual=1"
                                    onclick="return confirm('Start deployment?')"
                                    class="block w-full text-center bg-slate-900 dark:bg-slate-100 text-white dark:text-slate-900 py-3 rounded-xl text-xs font-bold hover:opacity-90 transition uppercase tracking-[0.18em]">Manual deploy</a>
                            <?php endif; ?>

                            <?php if (env('MODE', 'production') !== 'production'): ?>
                                <a href="/debugdeploy"
                                    target="_blank"
                                    onclick="return confirm('Debug deployment?')"
                                    class="block w-full text-center bg-amber-700 text-white py-3 rounded-xl text-xs font-bold hover:bg-amber-600 transition uppercase tracking-[0.18em]">Debug deployment</a>
                            <?php endif; ?>

                            <?php if (isset($statusData['finished'])): ?>
                                <a href="/status/live" class="block w-full text-center border border-emerald-500/30 text-emerald-600 dark:text-emerald-400 py-3 rounded-xl text-[11px] font-bold hover:bg-emerald-500/5 transition uppercase tracking-[0.18em]">
                                    View last result
                                </a>
                            <?php endif; ?>

                            <a href="/test-notify" class="block w-full text-center border border-slate-200 dark:border-slate-800 hover:bg-slate-50 dark:hover:bg-slate-800 py-3 rounded-xl text-xs transition uppercase tracking-[0.18em]">Test notification</a>
                            <a href="/alllogs" class="block w-full text-center border border-slate-200 dark:border-slate-800 hover:bg-slate-50 dark:hover:bg-slate-800 py-3 rounded-xl text-xs transition uppercase tracking-[0.18em]">All logs</a>
                            <a href="/clear-history" onclick="return confirm('Clear all logs?')" class="block w-full text-center text-rose-500 hover:text-rose-400 py-2 text-xs transition uppercase tracking-[0.18em]">Clear history</a>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-[#161b2a] border border-slate-200 dark:border-slate-800 p-5 rounded-lg shadow-sm dark:shadow-xl text-xs">
                        <h2 class="text-slate-900 dark:text-white font-bold mb-4 border-b border-slate-100 dark:border-slate-800 pb-2 uppercase tracking-[0.2em]">System config</h2>
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

                    <div class="bg-white dark:bg-[#161b2a] border border-slate-200 dark:border-slate-800 p-5 rounded-lg shadow-sm dark:shadow-xl text-xs">
                        <h2 class="text-slate-900 dark:text-white font-bold mb-4 border-b border-slate-100 dark:border-slate-800 pb-2 uppercase tracking-[0.2em]">Artifact config</h2>
                        <div class="space-y-3 text-[11px]">
                            <?php

                            $artifactVars = [
                                ['label' => 'GitLab Token', 'isSet' => ! empty($config['gitlab_token'])],
                                [
                                    'label' => 'Instructions',
                                    'isSet' => $artifactInstructionExists,
                                    'value' => basename($config['artifact_instructions']),
                                ],
                                ['label' => 'Deploy Dir', 'isSet' => ! empty($config['artifact_deploy_dir'])],
                                ['label' => 'GitLab URL', 'isSet' => ! empty($config['gitlab_base_url'])],
                            ];
                            foreach ($artifactVars as $av): ?>
                                <div class="flex flex-row items-center justify-between gap-4">
                                    <div class="text-slate-400 dark:text-slate-500 uppercase truncate"><?= $av['label'] ?>

                                        <?php if (isset($av['value'])): ?>
                                            <span class="text-slate-400 dark:text-slate-600 text-[10px] truncate max-w-[80px] lowercase"><?= htmlspecialchars(
                                                $av['value'],
                                            ) ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="flex items-center gap-1 shrink-0">
                                        <span class="px-2 py-0.5 rounded font-bold <?= $av['isSet']
                                            ? 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-500'
                                            : 'bg-rose-500/10 text-rose-600 dark:text-rose-500' ?>">
                                            <?= $av['isSet'] ? '0K' : '??' ?>
                                        </span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            <?php if (! $artifactInstructionExists): ?>
                                <p class="text-rose-500 dark:text-rose-400 text-[10px] pt-1 break-all">
                                    Not found: <?= htmlspecialchars($config['artifact_instructions']) ?>
                                </p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </body>

    </html>
<?php
}

function renderLogsView()
{
    global $config;

    $type = $_GET['type'] ?? 'log';
    $validTypes = ['log', 'rlog', 'html', 'fraw'];
    if (! in_array($type, $validTypes))
        $type = 'log';

    $typeConfig = [
        'log' => [
            'glob' => '*.log',
            'url' => fn ($id) => "/log/rview/$id",
            'label' => 'LOG',
            'desc' => 'Formatted plain text log',
        ],
        'rlog' => [
            'glob' => '*.rlog',
            'url' => fn ($id) => "/log/bview/$id",
            'label' => 'RLOG',
            'desc' => 'Timestamped raw log',
        ],
        'html' => [
            'glob' => '*.html',
            'url' => fn ($id) => "/log/htmlview/$id",
            'label' => 'HTML',
            'desc' => 'Styled HTML log',
        ],
        'fraw' => [
            'glob' => '*.fraw',
            'url' => fn ($id) => "/log/frawview/$id",
            'label' => 'FRAW',
            'desc' => 'Full raw stream with signals',
        ],
    ];

    $logs = glob($config['logs_path'].'/'.$typeConfig[$type]['glob']) ?: [];
    usort($logs, fn ($a, $b) => filemtime($b) - filemtime($a));

    $tabColors = [
        'log' => 'indigo',
        'rlog' => 'violet',
        'html' => 'sky',
        'fraw' => 'amber',
    ];

    $resolveStatus = function ($logPath) use ($config) {
        $fn = basename($logPath);
        $id = preg_replace('/\.log.*$/', '', $fn);
        $baseLog = $config['logs_path'].'/'.$id.'.log';
        return resolveLogExecutionStatus($baseLog);
    };

    ?>
    <!DOCTYPE html>
    <html lang="en">

    <head>
        <meta charset="UTF-8">
        <script src="https://unpkg.com/@tailwindcss/browser@4"></script>
        <title>Logs — Deployer</title>
        <?= renderHeadImports() ?>
    </head>

    <body class="bg-[#f8fafc] dark:bg-[#0b0f1a] text-slate-600 dark:text-slate-300 p-8 font-mono text-sm transition-colors duration-200">
        <div class="max-w-6xl mx-auto">

            <!-- Header -->
            <div class="flex justify-between items-start mb-8">
                <div>
                    <h1 class="text-slate-900 dark:text-white font-bold text-xl tracking-tighter uppercase">SIMPLE PHP <span class="text-[#a855f7]">DEPLOYER</span></h1>
                    <p class="text-slate-400 dark:text-slate-500">Execution Logs</p>
                </div>
                <a href="/health" class="text-xs text-indigo-600 dark:text-indigo-400 hover:underline font-bold uppercase tracking-tighter">← Dashboard</a>
            </div>

            <!-- Tabs -->
            <div class="bg-white dark:bg-[#161b2a] border border-slate-200 dark:border-slate-800 rounded-lg shadow-sm dark:shadow-xl overflow-hidden">
                <div class="flex border-b border-slate-200 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-900/30">
                    <?php foreach ($typeConfig as $key => $cfg):
                        $active = $key === $type;
                        $color = $tabColors[$key];
                        $activeClass = $active
                            ? "border-b-2 border-{$color}-500 text-{$color}-600 dark:text-{$color}-400 bg-white dark:bg-[#161b2a]"
                            : 'text-slate-400 dark:text-slate-500 hover:text-slate-600 dark:hover:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800/40';
                        ?>
                        <a href="/alllogs?type=<?= $key ?>"
                            class="px-6 py-3 text-[10px] font-bold uppercase tracking-widest transition <?= $activeClass ?>">
                            <?= $cfg['label'] ?>
                            <?php if ($active): ?>
                                <span class="ml-1.5 text-[9px] opacity-60"><?= $cfg['desc'] ?></span>
                            <?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                </div>

                <!-- Table -->
                <div class="overflow-x-auto">
                    <?php if (! $logs): ?>
                        <div class="p-16 text-center text-slate-400 dark:text-slate-600 italic text-sm">
                            No <?= strtoupper($type) ?> logs found.
                        </div>
                    <?php else: ?>
                        <table class="w-full text-left border-collapse">
                            <thead>
                                <tr class="bg-slate-50 dark:bg-slate-900/50 text-slate-400 dark:text-slate-500 text-[10px] uppercase">
                                    <th class="px-6 py-3 font-bold border-b border-slate-100 dark:border-slate-800">File</th>
                                    <th class="px-6 py-3 font-bold border-b border-slate-100 dark:border-slate-800">Date</th>
                                    <th class="px-6 py-3 font-bold border-b border-slate-100 dark:border-slate-800 text-right">Size</th>
                                    <th class="px-6 py-3 font-bold border-b border-slate-100 dark:border-slate-800 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                                <?php foreach ($logs as $logPath):
                                    $fn = basename($logPath);
                                    $id = preg_replace('/\.log.*$/', '', $fn);
                                    $isOk = $resolveStatus($logPath);
                                    $size = filesize($logPath);
                                    $sizeLabel = formatBytesLabel($size);
                                    $viewUrl = $typeConfig[$type]['url']($id);
                                    ?>
                                    <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/20 transition">
                                        <td class="px-6 py-4 text-xs">
                                            <?php if ($isOk === true): ?>
                                                <span class="mr-2 text-emerald-500">●</span>
                                            <?php elseif ($isOk === false): ?>
                                                <span class="mr-2 text-rose-500">●</span>
                                            <?php else: ?>
                                                <span class="mr-2 text-slate-300 dark:text-slate-700">●</span>
                                            <?php endif; ?>
                                            <?= htmlspecialchars($fn) ?>
                                        </td>
                                        <td class="px-6 py-4 text-slate-400 dark:text-slate-600 text-xs">
                                            <?= date('Y-m-d H:i:s', filemtime($logPath)) ?>
                                        </td>
                                        <td class="px-6 py-4 text-slate-400 dark:text-slate-600 text-xs text-right tabular-nums">
                                            <?= $sizeLabel ?>
                                        </td>
                                        <td class="px-6 py-4 text-right">
                                            <a target="_blank" href="<?= $viewUrl ?>"
                                                class="inline-block px-3 py-1 rounded text-[10px] font-bold uppercase tracking-wide
                                                  bg-<?= $tabColors[$type] ?>-500/10 text-<?= $tabColors[$type] ?>-600 dark:text-<?= $tabColors[$type] ?>-400
                                                  hover:bg-<?= $tabColors[$type] ?>-500/20 transition">
                                                OPEN
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>

                <!-- Footer -->
                <div class="px-6 py-3 border-t border-slate-100 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-900/30 text-[10px] text-slate-400 dark:text-slate-600 flex justify-between">
                    <span><?= count($logs) ?> file(s) found</span>
                    <span><?= $typeConfig[$type]['desc'] ?></span>
                </div>
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

                        switch (status) {
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
                    const response = await fetch('/deploy/stop', {
                        method: 'POST'
                    });
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
