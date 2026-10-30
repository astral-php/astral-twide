<?php

declare(strict_types=1);

namespace Astral\Twide\Tests\Integration;

use Astral\Twide\Contracts\TemplateEngineInterface;
use Astral\Twide\Integration\TwideServiceProvider;
use Astral\Twide\Integration\TwideViewAdapter;
use Astral\Twide\Tests\Fixtures\StubGreetingExtension;
use Astral\Twide\Twide;
use Core\Container;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

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

    public function testRegistersApplicationExtensionsFromConfig(): void
    {
        $id = bin2hex(random_bytes(4));
        $views = sys_get_temp_dir() . '/twide-ext-views-' . $id;
        $cache = sys_get_temp_dir() . '/twide-ext-cache-' . $id;
        mkdir($views, 0770, true);
        file_put_contents($views . '/greet.twide', '{{ greet_stub(name) }}-{{ name | shout_stub }}');

        $container = new Container();
        $provider = new TwideServiceProvider();
        $provider->register($container, [
            'debug' => true,
            'twide' => [
                'views' => $views,
                'cache' => $cache,
                'extension' => '.twide',
                'extensions' => [StubGreetingExtension::class],
            ],
        ], []);

        $html = $container->make(Twide::class)->render('greet', ['name' => 'Ada']);
        self::assertSame('Hello Ada-ADA', $html);

        foreach (glob($views . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($views);
        foreach (glob($cache . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($cache);
    }

    public function testRejectsExtensionThatDoesNotImplementInterface(): void
    {
        $container = new Container();
        $provider = new TwideServiceProvider();
        $provider->register($container, [
            'twide' => [
                'views' => BASE_PATH . '/views',
                'extensions' => [stdClass::class],
            ],
        ], []);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must implement Astral\\Twide\\Contracts\\ExtensionInterface');
        $container->make(Twide::class);
    }

    public function testRejectsUnknownExtensionClass(): void
    {
        $container = new Container();
        $provider = new TwideServiceProvider();
        $provider->register($container, [
            'twide' => [
                'views' => BASE_PATH . '/views',
                'extensions' => ['App\\Does\\Not\\ExistExtension'],
            ],
        ], []);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('was not found');
        $container->make(Twide::class);
    }

    public function testRejectsInvalidExtensionSuffixInConfig(): void
    {
        $container = new Container();
        $provider = new TwideServiceProvider();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('twide.extension');
        $provider->register($container, [
            'twide' => [
                'views' => BASE_PATH . '/views',
                'extension' => 'views/bad',
            ],
        ], []);
    }
}
