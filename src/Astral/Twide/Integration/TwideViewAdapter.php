<?php

declare(strict_types=1);

namespace Astral\Twide\Integration;

use Astral\Twide\Contracts\TemplateEngineInterface;

/**
 * Framework-neutral adapter exposing view-oriented method names.
 *
 * This adapter intentionally has no dependency on astral-core. The host
 * framework can delegate its own view rendering to this adapter once its
 * concrete View contract has been selected by the integration layer.
 */
final class TwideViewAdapter
{
    public function __construct(private readonly TemplateEngineInterface $engine)
    {
    }

    /**
     * Render a view and return its HTML without sending an HTTP response.
     *
     * @param array<string, mixed> $data
     */
    public function renderView(string $view, array $data = []): string
    {
        return $this->engine->render($view, $data);
    }

    public function viewExists(string $view): bool
    {
        return $this->engine->exists($view);
    }

    public function engine(): TemplateEngineInterface
    {
        return $this->engine;
    }
}
