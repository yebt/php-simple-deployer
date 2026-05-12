<?php

declare(strict_types=1);

namespace Sphpd\Core;

/**
 * Regex-based HTTP router.
 *
 * PHP 7.4 compatible — no named arguments, no union types in signatures.
 */
class Router
{
    /** @var array<string, callable> */
    private array $routes = [];

    /** @var callable|null */
    private $notFound = null;

    /**
     * Register a route.
     *
     * @param string   $pattern  Path pattern (no delimiters, no anchors).
     * @param callable $callback Handler; capture groups are passed as arguments.
     */
    public function add(string $pattern, callable $callback): void
    {
        $this->routes['#^' . $pattern . '$#'] = $callback;
    }

    /**
     * Register a custom 404 handler.
     *
     * @param callable $callback
     */
    public function setNotFound(callable $callback): void
    {
        $this->notFound = $callback;
    }

    /**
     * Resolve a URI path against registered routes.
     *
     * @param string $uri  Full request URI or just the path.
     * @return mixed       Whatever the matched handler returns.
     */
    public function resolve(string $uri)
    {
        $path = (string) parse_url($uri, PHP_URL_PATH);

        foreach ($this->routes as $pattern => $callback) {
            if (preg_match($pattern, $path, $matches)) {
                array_shift($matches);
                return call_user_func_array($callback, $matches);
            }
        }

        $handler = $this->notFound ?? static function (): void {
            http_response_code(404);
            echo '404 Not Found';
        };

        return call_user_func($handler);
    }
}
