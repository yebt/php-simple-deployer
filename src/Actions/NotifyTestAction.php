<?php

declare(strict_types=1);

namespace Sphpd\Actions;

use Sphpd\Core\Config;
use Sphpd\Domain\Notifier\TelegramNotifier;

/**
 * GET /test-notify — sends a test Telegram notification and redirects.
 */
class NotifyTestAction
{
    private Config $config;
    private TelegramNotifier $notifier;

    public function __construct(Config $config, TelegramNotifier $notifier)
    {
        $this->config   = $config;
        $this->notifier = $notifier;
    }

    public function __invoke(): void
    {
        $server   = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $protocol = isset($_SERVER['HTTPS']) ? 'https://' : 'http://';
        $route    = (string) $this->config->get('dashboard_route');
        $returnTo = $_GET['return'] ?? $route;

        $date = new \DateTime('now', new \DateTimeZone('America/Bogota'));
        $formattedDate = $date->format('Y-m-d H:i:s');
        $dashboardUrl  = $protocol . $server . '/' . $returnTo;

        $escapedDate = $this->notifier->escapeMarkdown($formattedDate);
        $escapedHost = $this->notifier->escapeMarkdown($server);

        $message = "*System Check: SPHPD*\n\n"
            . "*Host:* `{$escapedHost}`\n"
            . "*Status:* `Operational`\n"
            . "*Timestamp:* _{$escapedDate}_\n\n"
            . "[Ver Dashboard]($dashboardUrl)";

        $ok = $this->notifier->send($message);

        header('Location: /' . $returnTo . '?notified=' . ($ok ? '1' : '0'));
    }
}
