<?php

declare(strict_types=1);

namespace Astral\Twide\Contracts;

interface TemplateEngineInterface
{
    /**
     * Render a template with the supplied data.
     *
     * @param array<string, mixed> $data
     */
    public function render(string $template, array $data = []): string;

    public function exists(string $template): bool;
}
