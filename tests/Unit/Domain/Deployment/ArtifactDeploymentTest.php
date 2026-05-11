<?php

declare(strict_types=1);

use Sphpd\Core\Config;
use Sphpd\Domain\Deployment\ArtifactDeployment;
use Sphpd\Domain\Deployment\ProcessRunner;
use Sphpd\Domain\Instructions\Instructions;
use Sphpd\Domain\Logger\Logger;
use Sphpd\Domain\Notifier\TelegramNotifier;

// ── Helpers ───────────────────────────────────────────────────────────────────

function makeArtifactDeployment(
    array $envOverrides = [],
    ?callable $runnerStub = null,
    ?callable $downloaderStub = null
): array {
    $tmpDir      = sys_get_temp_dir().'/sphpd_art_'.uniqid();
    mkdir($tmpDir);
    $logsDir     = $tmpDir.'/logs';
    mkdir($logsDir);
    $deployDir   = $tmpDir.'/artifact-deploy';
    mkdir($deployDir);
    $statusFile  = $tmpDir.'/status.json';
    $instrFile   = $tmpDir.'/artifact-deploy.json';

    $env = array_merge([
        'LOGS_PATH'                  => $logsDir,
        'GITLAB_TOKEN'               => 'test-token',
        'GITLAB_BASE_URL'            => 'https://gitlab.example.com',
        'ARTIFACT_DEPLOY_DIR'        => $deployDir,
        'ARTIFACT_INSTRUCTIONS_FILE' => $instrFile,
    ], $envOverrides);

    $config     = new Config($env);
    $notifier   = new TelegramNotifier('', '', null, $logsDir); // no-op
    $logger     = new Logger($logsDir, $statusFile);
    $instructions = new Instructions();

    $runner = $runnerStub
        ? new class($runnerStub) extends ProcessRunner {
            private $fn;
            public function __construct(callable $fn) { $this->fn = $fn; }
            public function run(string $command, ?string $cwd = null): array {
                return ($this->fn)($command, $cwd);
            }
          }
        : new ProcessRunner();

    $deployment = new ArtifactDeployment(
        $config, $runner, $instructions, $logger, $notifier, $statusFile, $downloaderStub
    );

    return [
        'deployment' => $deployment,
        'tmpDir'     => $tmpDir,
        'instrFile'  => $instrFile,
        'deployDir'  => $deployDir,
        'statusFile' => $statusFile,
    ];
}

function cleanupArtifact(string $tmpDir): void
{
    exec('rm -rf '.escapeshellarg($tmpDir));
}

// ── Validation guards ─────────────────────────────────────────────────────────

test('ArtifactDeployment: returns error when projectId is empty', function () {
    ['deployment' => $dep, 'tmpDir' => $dir] = makeArtifactDeployment();

    $result = $dep->run('', 'deploy', '123');

    expect($result['success'])->toBeFalse();
    expect($result['error'])->toContain('projectId');

    cleanupArtifact($dir);
});

test('ArtifactDeployment: returns error when GITLAB_TOKEN is not set', function () {
    ['deployment' => $dep, 'tmpDir' => $dir] = makeArtifactDeployment(['GITLAB_TOKEN' => '']);

    $result = $dep->run('42', 'deploy', '99');

    expect($result['success'])->toBeFalse();
    expect($result['error'])->toContain('GITLAB_TOKEN');

    cleanupArtifact($dir);
});

test('ArtifactDeployment: returns error when instructions file is missing', function () {
    $dl = function (): array { return ['code' => 200, 'error' => '']; };
    ['deployment' => $dep, 'tmpDir' => $dir, 'deployDir' => $dd] = makeArtifactDeployment([], null, $dl);

    // Create a fake artifact file so the downloader "succeeds"
    file_put_contents($dd.'/artifact.zip', 'fake');
    // instrFile NOT created

    $result = $dep->run('42', 'deploy', '99');

    expect($result['success'])->toBeFalse();
    expect($result['error'])->toContain('not found');

    cleanupArtifact($dir);
});

test('ArtifactDeployment: blocks concurrent deployment via status file', function () {
    ['deployment' => $dep, 'tmpDir' => $dir, 'statusFile' => $sf] = makeArtifactDeployment();

    file_put_contents($sf, json_encode(['running' => true, 'finished' => false]));

    $result = $dep->run('42', 'deploy', '99');

    expect($result['success'])->toBeFalse();
    expect($result['error'])->toContain('in progress');

    cleanupArtifact($dir);
});

// ── Download failure ──────────────────────────────────────────────────────────

test('ArtifactDeployment: returns error when download fails', function () {
    $dl = function (): array { return ['code' => 500, 'error' => 'connection refused']; };

    ['deployment' => $dep, 'tmpDir' => $dir, 'instrFile' => $instr] = makeArtifactDeployment([], null, $dl);
    file_put_contents($instr, json_encode([['name' => 'Deploy', 'run' => 'echo hi']]));

    $result = $dep->run('42', 'deploy', '99');

    expect($result['success'])->toBeFalse();
    expect($result['error'])->toContain('download failed');

    cleanupArtifact($dir);
});

// ── extract() ─────────────────────────────────────────────────────────────────

test('ArtifactDeployment: extract returns true for valid zip', function () {
    ['deployment' => $dep, 'tmpDir' => $dir] = makeArtifactDeployment();

    $zipFile = $dir.'/test.zip';
    $destDir = $dir.'/extracted';
    mkdir($destDir);

    $zip = new ZipArchive();
    $zip->open($zipFile, ZipArchive::CREATE);
    $zip->addFromString('hello.txt', 'hello');
    $zip->close();

    expect($dep->extract($zipFile, $destDir))->toBeTrue();
    expect(file_exists($destDir.'/hello.txt'))->toBeTrue();

    cleanupArtifact($dir);
});

test('ArtifactDeployment: extract returns false for invalid zip', function () {
    ['deployment' => $dep, 'tmpDir' => $dir] = makeArtifactDeployment();

    $badFile = $dir.'/bad.zip';
    file_put_contents($badFile, 'this is not a zip');

    expect($dep->extract($badFile, $dir))->toBeFalse();

    cleanupArtifact($dir);
});

// ── Full run ──────────────────────────────────────────────────────────────────

test('ArtifactDeployment: run succeeds with valid zip and instructions', function () {
    // Build a valid zip with a placeholder file
    $zipTmp = tempnam(sys_get_temp_dir(), 'sphpd_zip_');
    $zip    = new ZipArchive();
    $zip->open($zipTmp, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('app.txt', 'content');
    $zip->close();

    $zipContent = file_get_contents($zipTmp);
    unlink($zipTmp);

    $dl = function (string $url, string $destFile) use ($zipContent): array {
        file_put_contents($destFile, $zipContent);
        return ['code' => 200, 'error' => ''];
    };

    $runnerStub = function (): array {
        return ['stdout' => 'done', 'stderr' => '', 'exitCode' => 0, 'timedOut' => false];
    };

    ['deployment' => $dep, 'tmpDir' => $dir, 'instrFile' => $instr] =
        makeArtifactDeployment([], $runnerStub, $dl);

    file_put_contents($instr, json_encode([['name' => 'Install', 'run' => 'echo install']]));

    $result = $dep->run('42', 'deploy', '99');

    expect($result['success'])->toBeTrue();
    expect($result['error'])->toBe('');

    cleanupArtifact($dir);
});
