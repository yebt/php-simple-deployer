<?php

declare(strict_types=1);

namespace Sphpd\Actions;

use Sphpd\Core\Config;
use Sphpd\Core\Security;
use Sphpd\Support\SelfUpdater;

/**
 * GET /script/update — runs self-update and redirects.
 */
class SelfUpdateAction
{
    private Config $config;
    private Security $security;
    private SelfUpdater $updater;

    public function __construct(Config $config, Security $security, SelfUpdater $updater)
    {
        $this->config   = $config;
        $this->security = $security;
        $this->updater  = $updater;
    }

    public function __invoke(): void
    {
        $this->security->assertValid();

        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            http_response_code(405);
            exit('Method Not Allowed');
        }

        $returnTo = $this->normalizeDashboardReturn($_GET['return'] ?? '');

        try {
            $result = $this->updater->update();
            $query  = http_build_query([
                'updated' => '1',
                'backup'  => 'backups/' . basename($result['backup_path']),
            ]);
        } catch (\Throwable $e) {
            $query = http_build_query([
                'updated' => '0',
                'message' => $e->getMessage(),
            ]);
        }

        header('Location: /' . $returnTo . ($query ? '?' . $query : ''));
        exit();
    }

    private function normalizeDashboardReturn(string $route): string
    {
        $fallback = (string) $this->config->get('dashboard_route');

        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $route)) {
            return $fallback;
        }

        return $route;
    }
}
