<?php

declare(strict_types=1);

namespace Sphpd\Core;

use Latte\Engine;

/**
 * Thin wrapper around Latte\Engine.
 *
 * Resolves template paths relative to the templates/ directory and
 * renders to the output buffer.
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
    }

    /**
     * Render a template by name (relative to templates/).
     *
     * @param string               $template  e.g. 'health.latte' or 'partials/flash.latte'
     * @param array<string, mixed> $params
     */
    public function render(string $template, array $params = []): void
    {
        $path = $this->templatesDir . '/' . $template;
        $this->latte->render($path, $params);
    }

    /**
     * Render to string instead of outputting.
     *
     * @param string               $template
     * @param array<string, mixed> $params
     */
    public function renderToString(string $template, array $params = []): string
    {
        $path = $this->templatesDir . '/' . $template;
        return $this->latte->renderToString($path, $params);
    }
}
