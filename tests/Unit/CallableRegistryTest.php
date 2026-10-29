<?php

declare(strict_types=1);

namespace Astral\Twide\Tests\Unit;

use Astral\Twide\Exceptions\ExtensionRegistrationException;
use Astral\Twide\Registry\FilterRegistry;
use Astral\Twide\Registry\FunctionRegistry;
use PHPUnit\Framework\TestCase;

final class CallableRegistryTest extends TestCase
{
    public function testFunctionCanBeRegisteredAndRetrieved(): void
    {
        $registry = new FunctionRegistry();
        $registry->register('greet', static fn (string $name): string => 'Hello ' . $name);

        self::assertSame('Hello Ada', ($registry->get('greet'))('Ada'));
    }

    public function testDuplicateNamesAreRejectedByDefault(): void
    {
        $registry = new FilterRegistry();
        $registry->register('upper', 'strtoupper');

        $this->expectException(ExtensionRegistrationException::class);
        $registry->register('upper', 'strtolower');
    }

    public function testExplicitReplacementIsSupported(): void
    {
        $registry = new FunctionRegistry();
        $registry->register('value', static fn (): string => 'first');
        $registry->register('value', static fn (): string => 'second', true);

        self::assertSame('second', ($registry->get('value'))());
    }
}
