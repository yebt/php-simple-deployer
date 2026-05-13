<?php

declare(strict_types=1);

if (!function_exists('env')) {
    /**
     * Read a value from $_ENV with an optional default.
     *
     * String booleans ("true"/"false") and "null" are cast to their
     * PHP equivalents, consistent with how Config::env() works internally.
     *
     * @param mixed $default
     *
     * @return mixed
     */
    function env(string $key, $default = null)
    {
        if (empty($_ENV)) {
            return $default;
        }

        if (!array_key_exists($key, $_ENV)) {
            return $default;
        }

        $value = $_ENV[$key];

        if (is_string($value)) {
            $lower = strtolower($value);
            if ('true' === $lower) {
                return true;
            }
            if ('false' === $lower) {
                return false;
            }
            if ('null' === $lower) {
                return null;
            }
        }

        return $value;
    }
}
