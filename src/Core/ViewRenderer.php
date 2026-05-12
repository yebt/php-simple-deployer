<?php

declare(strict_types=1);

namespace Sphpd\Core;

use Latte\Engine;
use Latte\Loaders\FileLoader;

/**
 * Thin wrapper around Latte\Engine.
 *
 * Resolves template paths relative to the templates/ directory and
 * renders to the output buffer.
 *
 * Setting a baseDir on FileLoader ensures {layout} and {include} directives
 * resolve relative to that root — critical for PHAR compatibility.
 *
 * PHP 7.4 compatible.
 */
class ViewRenderer
{
    private Engine $latte;
    private string $templatesDir;

    public function __construct(string $templatesDir, string $cacheDir)
    {
        $this->templatesDir = rtrim($templatesDir, '/');

        $this->latte = new Engine();
        $this->latte->setTempDirectory($cacheDir);
        $this->latte->setLoader(new FileLoader($this->templatesDir));
        $this->latte->addFilter('json_encode', static function ($value, int $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES): string {
            return (string) json_encode($value, $flags);
        });
    }

    /**
     * Render a template by name (relative to templates/).
     *
     * @param string               $template e.g. 'health.latte' or 'partials/flash.latte'
     * @param array<string, mixed> $params
     */
    public function render(string $template, array $params = []): void
    {
        $this->latte->render($template, $params);
    }

    /**
     * Render to string instead of outputting.
     *
     * @param array<string, mixed> $params
     */
    public function renderToString(string $template, array $params = []): string
    {
        return $this->latte->renderToString($template, $params);
    }
}
