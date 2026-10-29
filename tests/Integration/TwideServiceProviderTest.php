<?php

declare(strict_types=1);

namespace Astral\Twide\Tests\Integration;

use Astral\Twide\Contracts\TemplateEngineInterface;
use Astral\Twide\Integration\TwideServiceProvider;
use Astral\Twide\Integration\TwideViewAdapter;
use Astral\Twide\Twide;
use Core\Container;
use PHPUnit\Framework\TestCase;

final class TwideServiceProviderTest extends TestCase
{
    public function testRegistersEngineInterfaceAndAdapterAsSharedServices(): void
    {
        $container = new Container();
        $provider = new TwideServiceProvider();
        $provider->register($container, ['debug' => true], []);

        $engine = $container->make(Twide::class);
        self::assertInstanceOf(Twide::class, $engine);
        self::assertInstanceOf(TemplateEngineInterface::class, $container->make(TemplateEngineInterface::class));
        self::assertSame($engine, $container->make(TemplateEngineInterface::class));

        $adapter = $container->make(TwideViewAdapter::class);
        self::assertInstanceOf(TwideViewAdapter::class, $adapter);
        self::assertSame($adapter, $container->make(TwideViewAdapter::class));
        self::assertTrue($adapter->viewExists('provider-smoke'));
    }
}
