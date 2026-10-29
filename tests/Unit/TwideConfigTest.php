<?php

declare(strict_types=1);

namespace Astral\Twide\Tests\Unit;

use Astral\Twide\Configuration\TwideConfig;
use Astral\Twide\Exceptions\InvalidConfigurationException;
use PHPUnit\Framework\TestCase;

final class TwideConfigTest extends TestCase
{
    public function testDefaultsAreApplied(): void
    {
        $config = new TwideConfig(['views' => __DIR__]);

        self::assertSame('.twide', $config->extension);
        self::assertFalse($config->debug);
        self::assertTrue($config->cacheEnabled);
        self::assertNotSame('', $config->cachePath);
    }

    public function testViewsPathIsRequired(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        new TwideConfig([]);
    }

    public function testExtensionCannotContainDirectorySeparator(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        new TwideConfig(['views' => __DIR__, 'extension' => 'views/file']);
    }
}
