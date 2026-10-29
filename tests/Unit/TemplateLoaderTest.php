<?php

declare(strict_types=1);

namespace Astral\Twide\Tests\Unit;

use Astral\Twide\Configuration\TwideConfig;
use Astral\Twide\Exceptions\TemplateNotFoundException;
use Astral\Twide\Loader\TemplateLoader;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class TemplateLoaderTest extends TestCase
{
    private string $root;
    private TemplateLoader $loader;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/twide-tests-' . bin2hex(random_bytes(5));
        mkdir($this->root, 0770, true);
        file_put_contents($this->root . '/hello.twide', 'Hello {{ name }}');
        mkdir($this->root . '/partials');
        file_put_contents($this->root . '/partials/card.twide', 'Card');

        $this->loader = new TemplateLoader(new TwideConfig([
            'views' => $this->root,
            'extension' => '.twide',
        ]));
    }

    protected function tearDown(): void
    {
        @unlink($this->root . '/hello.twide');
        @unlink($this->root . '/partials/card.twide');
        @rmdir($this->root . '/partials');
        @rmdir($this->root);
    }

    public function testLoadsTemplateAndAddsDefaultExtension(): void
    {
        self::assertSame('Hello {{ name }}', $this->loader->load('hello'));
        self::assertTrue($this->loader->exists('partials/card'));
    }

    public function testMissingTemplateThrowsDedicatedException(): void
    {
        $this->expectException(TemplateNotFoundException::class);
        $this->loader->load('missing');
    }

    public function testTraversalIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->loader->load('../outside');
    }

    public function testAbsolutePathIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->loader->load('/etc/passwd');
    }
}
