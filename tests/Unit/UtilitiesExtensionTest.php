<?php

declare(strict_types=1);

namespace Astral\Twide\Tests\Unit;

use Astral\Twide\Extension\UtilitiesExtension;
use PHPUnit\Framework\TestCase;

final class UtilitiesExtensionTest extends TestCase
{
    public function testExtensionRegistersUtilityFunctionsAndFilters(): void
    {
        $extension = new UtilitiesExtension();

        self::assertArrayHasKey('str_slug', $extension->getFunctions());
        self::assertArrayHasKey('date_format', $extension->getFunctions());
        self::assertArrayHasKey('arr_get', $extension->getFunctions());
        self::assertArrayHasKey('slug', $extension->getFilters());
        self::assertArrayHasKey('date', $extension->getFilters());
        self::assertArrayHasKey('flatten', $extension->getFilters());
    }

    public function testStringFilterDelegatesToAstralUtilities(): void
    {
        $extension = new UtilitiesExtension();
        $filters = $extension->getFilters();

        self::assertSame('cafe-astral', $filters['slug']('Café Astral'));
        self::assertSame('Cafe Astral', $filters['ascii']('Café Astral'));
    }

    public function testArrayFilterRejectsNonArrayInput(): void
    {
        $extension = new UtilitiesExtension();
        $filters = $extension->getFilters();

        $this->expectException(\InvalidArgumentException::class);
        $filters['arr_only']('not-an-array', ['id']);
    }
}
