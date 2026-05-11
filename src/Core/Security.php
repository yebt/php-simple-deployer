<?php

declare(strict_types=1);

namespace Sphpd\Core;

/**
 * Validates that an incoming request is authorised.
 *
 * All superglobal access is injected so the class is fully testable.
 * PHP 7.4 compatible.
 */
class Security
{
    private string $secureToken;

    public function __construct(string $secureToken)
    {
        $this->secureToken = $secureToken;
    }

    /**
     * Returns true when the request is allowed, false otherwise.
     *
     * @param array<string, string> $headers   HTTP headers (case-insensitive)
     * @param array<string, string> $query     $_GET equivalent
     * @param string|null           $clientIp  REMOTE_ADDR equivalent
     * @param bool                  $isManual  true when ?manual=1 is set
     */
    public function isAuthorised(
        array $headers,
        array $query = [],
        ?string $clientIp = null,
        bool $isManual = false
    ): bool {
        // No token configured → open access
        if ($this->secureToken === '') {
            return true;
        }

        // Manual trigger from UI → skip token check
        if ($isManual) {
            return true;
        }

        // Localhost always allowed
        if (in_array($clientIp, ['localhost', '127.0.0.1', '::1'], true)) {
            return true;
        }

        $lowerHeaders = array_change_key_case($headers, CASE_LOWER);
        $token = $lowerHeaders['x-deploy-token'] ?? $query['token'] ?? '';

        return $token === $this->secureToken;
    }

    /**
     * Build a normalised headers array from $_SERVER.
     *
     * Drop-in replacement for getallheaders() when that function is absent.
     *
     * @param array<string, string> $server  $_SERVER equivalent
     * @return array<string, string>
     */
    public static function headersFromServer(array $server): array
    {
        if (function_exists('getallheaders')) {
            // @codeCoverageIgnoreStart
            return getallheaders();
            // @codeCoverageIgnoreEnd
        }

        $headers = [];
        foreach ($server as $key => $value) {
            if (strpos($key, 'HTTP_') === 0) {
                $name = str_replace(
                    ' ', '-',
                    ucwords(strtolower(str_replace('_', ' ', substr($key, 5))))
                );
                $headers[$name] = $value;
            }
        }

        foreach (['CONTENT_TYPE', 'CONTENT_LENGTH', 'CONTENT_MD5', 'AUTHORIZATION'] as $key) {
            if (isset($server[$key])) {
                $name = str_replace(
                    ' ', '-',
                    ucwords(strtolower(str_replace('_', ' ', $key)))
                );
                $headers[$name] = $server[$key];
            }
        }

        return $headers;
    }
}
