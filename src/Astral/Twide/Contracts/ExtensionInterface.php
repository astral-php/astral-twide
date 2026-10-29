<?php

declare(strict_types=1);

namespace Astral\Twide\Contracts;

/**
 * Contract for an extension that registers template functions and filters.
 *
 * Directive registration will be added with the compiler API in a later phase.
 */
interface ExtensionInterface
{
    /**
     * @return array<string, callable>
     */
    public function getFunctions(): array;

    /**
     * @return array<string, callable>
     */
    public function getFilters(): array;
}
