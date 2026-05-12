<?php

declare(strict_types=1);

namespace Sphpd\Domain\Instructions;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Loads and validates deployment instructions from JSON or YAML files.
 *
 * PHP 7.4 compatible — no union types, no named arguments.
 */
class Instructions
{
    /**
     * Load tasks from a file path (JSON or YAML).
     *
     * @return array{error: string}|array{tasks: array<int, array<string, mixed>>}
     */
    public function load(string $filePath): array
    {
        if (!file_exists($filePath)) {
            return ['error' => "Instructions file not found: {$filePath}"];
        }

        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        if ('yml' === $ext || 'yaml' === $ext) {
            return $this->loadYaml($filePath);
        }

        return $this->loadJson($filePath);
    }

    /**
     * Load tasks from a JSON string directly (no file I/O).
     *
     * @return array{error: string}|array{tasks: array<int, array<string, mixed>>}
     */
    public function loadFromJson(string $json): array
    {
        $tasks = json_decode($json, true);

        if (null === $tasks) {
            return ['error' => 'Invalid JSON: '.json_last_error_msg()];
        }

        return $this->validated($tasks);
    }

    /**
     * Load tasks from a YAML string directly (no file I/O).
     *
     * @return array{error: string}|array{tasks: array<int, array<string, mixed>>}
     */
    public function loadFromYaml(string $yaml): array
    {
        try {
            $tasks = Yaml::parse($yaml);
        } catch (ParseException $e) {
            return ['error' => 'Invalid YAML: '.$e->getMessage()];
        }

        return $this->validated($tasks);
    }

    /**
     * Validate a tasks array.
     * Returns true on success, or a string error message.
     *
     * @param mixed $tasks
     *
     * @return string|true
     */
    public function validate($tasks)
    {
        if (!is_array($tasks)) {
            return 'Invalid instructions format: expected an array of tasks.';
        }

        foreach ($tasks as $index => $task) {
            if (!is_array($task)) {
                return 'Invalid Instructions format';
            }

            $run = $task['run'] ?? null;

            if (null === $run || ('' === $run && '0' !== $run && 0 !== $run)) {
                $name = $task['name'] ?? 'Task #'.($index + 1);

                return "Task '{$name}' is missing a 'run' command.";
            }

            if (!is_string($run) && !is_array($run)) {
                $name = $task['name'] ?? 'Task #'.($index + 1);

                return "Task '{$name}' 'run' must be a string or an array.";
            }
        }

        return true;
    }

    // -------------------------------------------------------------------------

    /** @return array{error: string}|array{tasks: array<int, array<string, mixed>>} */
    private function loadJson(string $filePath): array
    {
        $content = file_get_contents($filePath);

        if (false === $content) {
            return ['error' => "Cannot read file: {$filePath}"];
        }

        return $this->loadFromJson($content);
    }

    /** @return array{error: string}|array{tasks: array<int, array<string, mixed>>} */
    private function loadYaml(string $filePath): array
    {
        $content = file_get_contents($filePath);

        if (false === $content) {
            return ['error' => "Cannot read file: {$filePath}"];
        }

        return $this->loadFromYaml($content);
    }

    /**
     * @param mixed $tasks
     *
     * @return array{error: string}|array{tasks: array<int, array<string, mixed>>}
     */
    private function validated($tasks): array
    {
        $result = $this->validate($tasks);

        if (true !== $result) {
            return ['error' => $result];
        }

        return ['tasks' => $tasks];
    }
}
