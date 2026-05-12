<?php

declare(strict_types=1);

use Sphpd\Core\Config;
use Sphpd\Domain\Deployment\Deployment;
use Sphpd\Domain\Deployment\ProcessRunner;
use Sphpd\Domain\Instructions\Instructions;
use Sphpd\Domain\Logger\Logger;
use Sphpd\Domain\Notifier\TelegramNotifier;

// ── Helpers ───────────────────────────────────────────────────────────────────

function makeDeployment(array $envOverrides = [], ?callable $runnerOverride = null): array
{
    $tmpDir      = sys_get_temp_dir().'/sphpd_dep_'.uniqid();
    mkdir($tmpDir);
    $logsDir     = $tmpDir.'/logs';
    mkdir($logsDir);
    $projectDir  = $tmpDir.'/project';
    mkdir($projectDir);
    $statusFile  = $tmpDir.'/status.json';
    $instrFile   = $tmpDir.'/deploy.json';

    $env = array_merge([
        'PROJECT_PATH'     => $projectDir,
        'INSTRUCTIONS_FILE' => $instrFile,
        'LOGS_PATH'        => $logsDir,
        'SECURITY_TOKEN'   => '',
    ], $envOverrides);

    $config     = new Config($env);
    $notifier   = new TelegramNotifier('', '', null, $logsDir); // no-op: empty token
    $logger     = new Logger($logsDir, $statusFile);
    $instructions = new Instructions();

    // Swap ProcessRunner with a stub if provided
    if ($runnerOverride !== null) {
        $runner = new class($runnerOverride) extends ProcessRunner {
            private $fn;
            public function __construct(callable $fn) { $this->fn = $fn; }
            public function run(string $command, ?string $cwd = null, ?callable $onOutput = null): array {
                return ($this->fn)($command, $cwd);
            }
        };
    } else {
        $runner = new ProcessRunner();
    }

    $deployment = new Deployment($config, $runner, $instructions, $logger, $notifier, $statusFile);

    return ['deployment' => $deployment, 'tmpDir' => $tmpDir, 'instrFile' => $instrFile, 'statusFile' => $statusFile];
}

function cleanup(string $tmpDir): void
{
    exec('rm -rf '.escapeshellarg($tmpDir));
}

// ── validate() ────────────────────────────────────────────────────────────────

test('Deployment: validate returns error when project_path is missing', function () {
    ['deployment' => $dep, 'tmpDir' => $dir] = makeDeployment(['PROJECT_PATH' => '']);

    expect($dep->validate())->toContain('project_path');

    cleanup($dir);
});

test('Deployment: validate returns error when instructions file does not exist', function () {
    ['deployment' => $dep, 'tmpDir' => $dir] = makeDeployment();
    // instrFile NOT created on disk

    expect($dep->validate())->toContain('not found');

    cleanup($dir);
});

test('Deployment: validate returns null when config and instructions are valid', function () {
    ['deployment' => $dep, 'tmpDir' => $dir, 'instrFile' => $instr] = makeDeployment();
    file_put_contents($instr, json_encode([['name' => 'Test', 'run' => 'echo ok']]));

    expect($dep->validate())->toBeNull();

    cleanup($dir);
});

// ── run() ─────────────────────────────────────────────────────────────────────

test('Deployment: run returns success when all commands exit 0', function () {
    $stub = function (string $cmd, ?string $cwd): array {
        return ['stdout' => 'ok', 'stderr' => '', 'exitCode' => 0, 'timedOut' => false];
    };

    ['deployment' => $dep, 'tmpDir' => $dir, 'instrFile' => $instr] = makeDeployment([], $stub);
    file_put_contents($instr, json_encode([
        ['name' => 'Step 1', 'run' => 'echo ok'],
        ['name' => 'Step 2', 'run' => 'echo done'],
    ]));

    $result = $dep->run('localhost');

    expect($result['success'])->toBeTrue();
    expect($result['error'])->toBe('');

    cleanup($dir);
});

test('Deployment: run returns failure when a command exits non-zero', function () {
    $stub = function (string $cmd, ?string $cwd): array {
        return ['stdout' => '', 'stderr' => 'build failed', 'exitCode' => 1, 'timedOut' => false];
    };

    ['deployment' => $dep, 'tmpDir' => $dir, 'instrFile' => $instr] = makeDeployment([], $stub);
    file_put_contents($instr, json_encode([['name' => 'Build', 'run' => 'npm run build']]));

    $result = $dep->run('localhost');

    expect($result['success'])->toBeFalse();

    cleanup($dir);
});

test('Deployment: run returns error when instructions file is invalid JSON', function () {
    $stub = function (): array {
        return ['stdout' => '', 'stderr' => '', 'exitCode' => 0, 'timedOut' => false];
    };

    ['deployment' => $dep, 'tmpDir' => $dir, 'instrFile' => $instr] = makeDeployment([], $stub);
    file_put_contents($instr, '{not valid json');

    $result = $dep->run('localhost');

    expect($result['success'])->toBeFalse();
    expect($result['error'])->not->toBe('');

    cleanup($dir);
});

test('Deployment: run blocks concurrent deployment via status file', function () {
    $stub = function (): array {
        return ['stdout' => 'ok', 'stderr' => '', 'exitCode' => 0, 'timedOut' => false];
    };

    ['deployment' => $dep, 'tmpDir' => $dir, 'statusFile' => $sf] = makeDeployment([], $stub);

    // Simulate a deployment already in progress
    file_put_contents($sf, json_encode(['running' => true, 'finished' => false]));

    $result = $dep->run('localhost');

    expect($result['success'])->toBeFalse();
    expect($result['error'])->toContain('in progress');

    cleanup($dir);
});

test('Deployment: run clears stale finished status file and proceeds', function () {
    $stub = function (): array {
        return ['stdout' => 'ok', 'stderr' => '', 'exitCode' => 0, 'timedOut' => false];
    };

    ['deployment' => $dep, 'tmpDir' => $dir, 'instrFile' => $instr, 'statusFile' => $sf]
        = makeDeployment([], $stub);

    file_put_contents($instr, json_encode([['name' => 'Go', 'run' => 'echo go']]));
    // Stale status from a previously finished deployment
    file_put_contents($sf, json_encode(['running' => false, 'finished' => true]));

    $result = $dep->run('localhost');

    expect($result['success'])->toBeTrue();

    cleanup($dir);
});

test('Deployment: run creates log files in logs directory', function () {
    $stub = function (): array {
        return ['stdout' => 'output', 'stderr' => '', 'exitCode' => 0, 'timedOut' => false];
    };

    ['deployment' => $dep, 'tmpDir' => $dir, 'instrFile' => $instr] = makeDeployment([], $stub);
    file_put_contents($instr, json_encode([['name' => 'Task', 'run' => 'echo hi']]));

    $dep->run('localhost');

    $logs = glob($dir.'/logs/*.log');
    expect(count($logs))->toBeGreaterThan(0);

    cleanup($dir);
});
