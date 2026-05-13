<?php

declare(strict_types=1);

use Sphpd\Actions\DeployAction;
use Sphpd\Actions\HealthAction;
use Sphpd\Actions\LogAction;
use Sphpd\Actions\NotifyTestAction;
use Sphpd\Actions\SelfUpdateAction;
use Sphpd\Actions\StatusAction;
use Sphpd\Core\Config;
use Sphpd\Core\Router;
use Sphpd\Core\Security;
use Sphpd\Core\ViewRenderer;
use Sphpd\Domain\Deployment\ArtifactDeployment;
use Sphpd\Domain\Deployment\Deployment;
use Sphpd\Domain\Deployment\ProcessRunner;
use Sphpd\Domain\Instructions\Instructions;
use Sphpd\Domain\Logger\Logger;
use Sphpd\Domain\Notifier\TelegramNotifier;
use Sphpd\Support\SelfUpdater;

require_once __DIR__.'/../vendor/autoload.php';

// ── Load .env (only outside PHAR — the PHAR ships without .env) ───────────────

if ('' === Phar::running()) {
    $dotenvPath = __DIR__.'/..';
    if (file_exists($dotenvPath.'/.env')) {
        $dotenv = Dotenv\Dotenv::createImmutable($dotenvPath);
        $dotenv->load();
    }
}

//
// Try resolve some var that need __DIR__
define('ROOT_PATH', dirname(__DIR__));
// define('PROJECT_PATH', realpath(env('PROJECT_PATH', '')));

// ── Bootstrap ─────────────────────────────────────────────────────────────────

$baseDir = '' !== Phar::running()
    ? dirname($_SERVER['SCRIPT_FILENAME'] ?? __FILE__)
    : (string) realpath(__DIR__.'/..');

$config = new Config(null, $baseDir);

$logsPath = rtrim((string) ($config->get('logs_path') ?? $baseDir.'/logs'), '/');
$statusFile = $logsPath.'/.current_status';

$security = new Security((string) ($config->get('secure_token') ?? ''));

// Validate basic HTTP auth (user+pass from server env) before anything else.
$user = $_SERVER['PHP_AUTH_USER'] ?? '';
$pass = $_SERVER['PHP_AUTH_PW'] ?? '';
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($user && $pass && ('GET' === $method || 'POST' === $method)) {
    // Basic auth present — accepted (real credential check lives in server config).
} elseif ($user || $pass) {
    // Partial credentials — reject.
    http_response_code(401);
    header('WWW-Authenticate: Basic realm="Deployer"');

    exit('Unauthorized');
}

// ── Dependency graph ──────────────────────────────────────────────────────────

$runner = new ProcessRunner();

$instructions = new Instructions();

$logger = new Logger($logsPath, $statusFile);

$notifier = new TelegramNotifier(
    (string) ($config->get('bot_token') ?? ''),
    (string) ($config->get('chat_id') ?? ''),
    $config->get('thread_id'),
    $logsPath
);

$deployment = new Deployment(
    $config,
    $runner,
    $instructions,
    $logger,
    $notifier,
    $statusFile
);

$artifactDeployment = new ArtifactDeployment(
    $config,
    $runner,
    $instructions,
    $logger,
    $notifier,
    $statusFile
);

/** @var string $entryScript Path to this compiled/entry file (used by SelfUpdater). */
$entryScript = $_SERVER['SCRIPT_FILENAME'] ?? __FILE__;

$baseDirUpdater = constant('ROOT_PATH') ?? $entryScript;
$updater = new SelfUpdater(
    (string) ($config->get('self_update_url') ?? ''),
    // dirname($entryScript).'/backups',
    $baseDirUpdater.'/backups',
    $entryScript
);

$pharRoot = Phar::running();
$templatesDir = '' !== $pharRoot
    ? $pharRoot.'/templates'
    : __DIR__.'/../templates';

$view = new ViewRenderer(
    $templatesDir,
    sys_get_temp_dir().'/sphpd_latte_cache'
);

// ── Actions ───────────────────────────────────────────────────────────────────

$health = new HealthAction($config, $view, $updater, []);
$status = new StatusAction($config);
$log = new LogAction($config);
$deploy = new DeployAction($config, $security, $deployment, $artifactDeployment, $logger, $entryScript);
$notify = new NotifyTestAction($config, $notifier);
$selfUpd = new SelfUpdateAction($config, $security, $updater);

// ── Router ────────────────────────────────────────────────────────────────────

$router = new Router();

// Status / live
$router->add('/status/check', [$status, 'check']);
$router->add('/status/data', [$status, 'data']);
$router->add('/status/live', [$health, 'liveStatus']);
$router->add('/status/stream', [$deploy, 'stream']);
$router->add('/deploy/stop', [$status, 'stop']);

// Dashboard / health views
$router->add('/', [$health, 'home']);
$router->add('/health', [$health, 'health']);
$router->add('/health1', [$health, 'health1']);
$router->add('/health2', [$health, 'health2']);

// Logs
$router->add('/alllogs', [$health, 'allLogs']);
$router->add('/log/view', [$log, 'view']);
$router->add('/log/last', [$log, 'last']);
$router->add('/log/lasthtml', [$log, 'lastHtml']);
$router->add('/log/lastfraw', [$log, 'lastFraw']);
$router->add('/log/rview/([a-zA-Z0-9_]+)', function (string $id) use ($log): void {
    $log->rawView($id);
});
$router->add('/log/bview/([a-zA-Z0-9_]+)', function (string $id) use ($log): void {
    $log->baseRawView($id);
});
$router->add('/log/htmlview/([a-zA-Z0-9_]+)', function (string $id) use ($log): void {
    $log->htmlView($id);
});
$router->add('/log/frawview/([a-zA-Z0-9_]+)', function (string $id) use ($log): void {
    $log->frawView($id);
});
$router->add('/clear-history', [$log, 'clearHistory']);

// Webhooks
$router->add('/webhook/deploy', [$deploy, 'webhookDeploy']);
$router->add('/webhook/deploy/nowait', [$deploy, 'webhookDeployNoWait']);
$router->add('/webhook/artifact-deploy', [$deploy, 'webhookArtifactDeploy']);
$router->add('/webhook/artifact-deploy/nowait', [$deploy, 'webhookArtifactDeployNoWait']);

// Misc
$router->add('/test-notify', $notify);
$router->add('/script/update', $selfUpd);

// Debug (skip in production)
if ('production' !== env('APP_ENV', 'production')) {
    $router->add('/debugdeploy', [$deploy, 'debugDeploy']);
}

// 404
$router->setNotFound(function () use ($health): void {
    http_response_code(404);
    $health->notFound();
});

// ── CLI dispatch (background jobs launched by manual deploy) ─────────────────

if (PHP_SAPI === 'cli' && isset($argv[1])) {
    switch ($argv[1]) {
        case 'run-deploy':
            $host = $argv[2] ?? 'localhost';
            $deployment->run($host);

            exit(0);

        case 'run-artifact-deploy':
            $host = $argv[2] ?? 'localhost';
            $projectId = $argv[3] ?? '';
            $branch = $argv[4] ?? 'main';
            $job = $argv[5] ?? 'some';
            $jobId = $argv[6] ?? '';
            $artifactDeployment->run($projectId, $job, $jobId, $branch);

            exit(0);
    }

    exit(0);
}

// ── HTTP dispatch ─────────────────────────────────────────────────────────────

$uri = $_SERVER['REQUEST_URI'] ?? '/';
$router->resolve($uri);
