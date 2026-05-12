<?php

declare(strict_types=1);

namespace Sphpd\Core;

/**
 * Reads configuration from environment variables with typed defaults.
 *
 * PHP 7.4 compatible — no named arguments, no union types in signatures.
 */
class Config
{
    /** @var array<string, mixed> */
    private array $data;

    public function __construct(?array $env = null)
    {
        $source = $env ?? $_ENV;
        $this->data = $this->build($source);
    }

    /**
     * Get a config value by key.
     *
     * @return mixed
     */
    public function get(string $key)
    {
        return $this->data[$key] ?? null;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->data;
    }

    // -------------------------------------------------------------------------

    /** @param array<string, mixed> $env */
    private function build(array $env): array
    {
        return [
            'project_path'           => $this->env($env, 'PROJECT_PATH'),
            'instructions'           => $this->env($env, 'INSTRUCTIONS_FILE', 'deploy.json'),
            'logs_path'              => $this->env($env, 'LOGS_PATH', './logs'),
            'telegram_enabled'       => $this->env($env, 'TELEGRAM_NOTIFICATIONS', true),
            'bot_token'              => $this->env($env, 'TELEGRAM_BOT_TOKEN', ''),
            'chat_id'                => $this->env($env, 'TELEGRAM_CHAT_ID', ''),
            'thread_id'              => $this->env($env, 'TELEGRAM_THREAD_ID'),
            'secure_token'           => $this->env($env, 'SECURITY_TOKEN', ''),
            'webhook_method'         => $this->env($env, 'WEBHOOK_METHOD', 'POST'),
            'gitlab_token'           => $this->env($env, 'GITLAB_TOKEN', ''),
            'gitlab_base_url'        => $this->env($env, 'GITLAB_BASE_URL', 'https://gitlab.com'),
            'artifact_deploy_dir'    => $this->env($env, 'ARTIFACT_DEPLOY_DIR', __DIR__.'/../../artifact-deploy'),
            'artifact_instructions'  => $this->env($env, 'ARTIFACT_INSTRUCTIONS_FILE', 'artifact-deploy.json'),
            'dashboard_route'        => $this->env($env, 'DASHBOARD_ROUTE', 'health'),
            'self_update_url'        => $this->env($env, 'SELF_UPDATE_URL', 'https://github.com/yebt/php-simple-deployer/releases/latest/download/index.php'),
        ];
    }

    /**
     * Read a key from an env array, coercing string booleans/null.
     *
     * @param array<string, mixed> $env
     * @param mixed $default
     * @return mixed
     */
    private function env(array $env, string $key, $default = null)
    {
        $value = $env[$key] ?? $default;

        if (is_string($value)) {
            $lower = strtolower($value);
            if ($lower === 'true')  return true;
            if ($lower === 'false') return false;
            if ($lower === 'null')  return null;
        }

        return $value;
    }
}
