<?php

declare(strict_types=1);

namespace Astral\Twide\Tests\Fixtures;

use Astral\Twide\Contracts\ExtensionInterface;

/**
 * Minimal ExtensionInterface stub for provider registration tests.
 */
final class StubGreetingExtension implements ExtensionInterface
{
    public function getFunctions(): array
    {
        return [
            'greet_stub' => static fn (string $name): string => 'Hello ' . $name,
        ];
    }

    public function getFilters(): array
    {
        return [
            'shout_stub' => static fn (mixed $value): string => strtoupper((string) $value),
        ];
    }
}
