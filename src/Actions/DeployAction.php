<?php

declare(strict_types=1);

namespace Sphpd\Actions;

use Sphpd\Core\Config;
use Sphpd\Core\Security;
use Sphpd\Domain\Deployment\Deployment;
use Sphpd\Domain\Deployment\ArtifactDeployment;
use Sphpd\Domain\Logger\Logger;

/**
 * Webhook deploy endpoints.
 *
 * /webhook/deploy
 * /webhook/deploy/nowait
 * /webhook/artifact-deploy
 * /webhook/artifact-deploy/nowait
 * /debugdeploy  (non-production only)
 */
class DeployAction
{
    private Config $config;
    private Security $security;
    private Deployment $deployment;
    private ArtifactDeployment $artifactDeployment;
    private Logger $logger;
    /** @var string */
    private string $entryScript;

    public function __construct(
        Config $config,
        Security $security,
        Deployment $deployment,
        ArtifactDeployment $artifactDeployment,
        Logger $logger,
        string $entryScript
    ) {
        $this->config             = $config;
        $this->security           = $security;
        $this->deployment         = $deployment;
        $this->artifactDeployment = $artifactDeployment;
        $this->logger             = $logger;
        $this->entryScript        = $entryScript;
    }

    private function dashboardUrl(string $query = ''): string
    {
        $route = (string) $this->config->get('dashboard_route');
        return '/' . $route . ($query ? '?' . $query : '');
    }

    private function host(): string
    {
        return $_SERVER['HTTP_HOST'] ?? 'localhost';
    }

    private function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    // -------------------------------------------------------------------------

    /** POST /webhook/deploy  (GET with ?manual=1 also accepted) */
    public function webhookDeploy(): void
    {
        $this->security->assertValid();

        $manual = isset($_GET['manual']) && $_GET['manual'] === '1';
        $method = $this->method();
        $configMethod = strtoupper((string) $this->config->get('webhook_method'));

        if ($method !== $configMethod && !($manual && $method === 'GET')) {
            http_response_code(405);
            exit('Method Not Allowed');
        }

        if ($manual) {
            $host = $this->host();
            exec('php ' . escapeshellarg($this->entryScript) . ' run-deploy ' . escapeshellarg($host) . ' > /dev/null 2>&1 &');
            usleep(500000);
            header('Location: ' . $this->dashboardUrl());
            exit();
        }

        $this->deployment->run($this->host());
    }

    /** POST /webhook/deploy/nowait */
    public function webhookDeployNoWait(): void
    {
        $this->security->assertValid();

        if ($this->method() !== strtoupper((string) $this->config->get('webhook_method'))) {
            http_response_code(405);
            exit('Method Not Allowed');
        }

        $host = $this->host();
        exec('php ' . escapeshellarg($this->entryScript) . ' run-deploy ' . escapeshellarg($host) . ' > /dev/null 2>&1 &');

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

        if ($this->method() !== 'POST') {
            http_response_code(405);
            exit('Method Not Allowed');
        }

        $contract = $this->parseArtifactInput();
        $host = $this->host();

        exec(
            'php ' . escapeshellarg($this->entryScript)
            . ' run-artifact-deploy '
            . escapeshellarg($host) . ' '
            . escapeshellarg((string) $contract['project_id']) . ' '
            . escapeshellarg((string) $contract['branch']) . ' '
            . escapeshellarg((string) $contract['job']) . ' '
            . escapeshellarg((string) $contract['job_id'])
            . ' > /dev/null 2>&1 &'
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

    // -------------------------------------------------------------------------

    /**
     * Validates security, method, reads + logs raw input, validates fields.
     * Returns the artifact contract array.
     *
     * @return array{project_id:string,branch:string,job:string,job_id:string}
     */
    private function checkArtifact(): array
    {
        $this->security->assertValid();

        if ($this->method() !== 'POST') {
            http_response_code(405);
            exit('Method Not Allowed');
        }

        $rawInput = (string) file_get_contents('php://input');
        $this->logger->logRequest($rawInput);

        return $this->parseArtifactInput($rawInput);
    }

    /**
     * @param string|null $rawInput  Pre-read body; reads php://input if null.
     * @return array{project_id:string,branch:string,job:string,job_id:string}
     */
    private function parseArtifactInput(?string $rawInput = null): array
    {
        if ($rawInput === null) {
            $rawInput = (string) file_get_contents('php://input');
        }

        $input     = json_decode($rawInput, true) ?? [];
        $projectId = $input['project_id'] ?? null;
        $branch    = $input['branch']     ?? 'main';
        $job       = $input['job']        ?? 'some';
        $jobId     = $input['job_id']     ?? null;

        if (!$projectId || !$jobId) {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Missing required fields: project_id']);
            exit();
        }

        return [
            'project_id' => (string) $projectId,
            'branch'     => (string) $branch,
            'job'        => (string) $job,
            'job_id'     => (string) $jobId,
        ];
    }
}
