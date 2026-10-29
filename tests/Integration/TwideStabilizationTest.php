<?php

declare(strict_types=1);

namespace Astral\Twide\Tests\Integration;

use Astral\Twide\Exceptions\TwideException;
use Astral\Twide\Twide;
use PHPUnit\Framework\TestCase;

final class TwideStabilizationTest extends TestCase
{
    private string $views;
    private string $cache;

    protected function setUp(): void
    {
        $id = bin2hex(random_bytes(5));
        $this->views = sys_get_temp_dir() . '/twide-stability-views-' . $id;
        $this->cache = sys_get_temp_dir() . '/twide-stability-cache-' . $id;
        mkdir($this->views, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach ([$this->views, $this->cache] as $directory) {
            foreach (glob($directory . '/*') ?: [] as $file) {
                if (is_file($file) || is_link($file)) {
                    @unlink($file);
                }
            }
            @rmdir($directory);
        }
    }

    private function engine(bool $cacheEnabled = true): Twide
    {
        return new Twide([
            'views' => $this->views,
            'cache' => $this->cache,
            'cache_enabled' => $cacheEnabled,
        ]);
    }

    public function testNestedComponentsResolveNamedAndDefaultSlots(): void
    {
        file_put_contents($this->views . '/outer.twide', '<article><header>@yield(\'title\')</header><div>@yield(\'slot\')</div></article>');
        file_put_contents($this->views . '/inner.twide', '<strong>@yield(\'slot\')</strong>');
        file_put_contents(
            $this->views . '/page.twide',
            "@component('outer')@slot('title')Hello@endslot@component('inner'){{ name }}@endcomponent@endcomponent"
        );

        self::assertSame('<article><header>Hello</header><div><strong>Ada</strong></div></article>', $this->engine()->render('page', ['name' => 'Ada']));
    }

    public function testCircularExtendsIsRejected(): void
    {
        file_put_contents($this->views . '/layout-a.twide', "@extends('layout-b')");
        file_put_contents($this->views . '/layout-b.twide', "@extends('layout-a')");

        $this->expectException(TwideException::class);
        $this->expectExceptionMessage('Circular template composition');
        $this->engine()->render('layout-a');
    }

    public function testNestedLoopRestoresOuterLoopMetadata(): void
    {
        file_put_contents(
            $this->views . '/loops.twide',
            '@for(group in groups){{ loop.index }}:@for(item in group){{ item }}{{ loop.parent.index }};@endfor/{{ loop.index }}|@endfor'
        );

        self::assertSame('1:a1;b1;/1|2:c2;/2|', $this->engine()->render('loops', ['groups' => [['a', 'b'], ['c']]]));
    }

    public function testCacheDisabledLeavesNoCompiledPhpFiles(): void
    {
        file_put_contents($this->views . '/plain.twide', 'plain output');

        self::assertSame('plain output', $this->engine(false)->render('plain'));
        self::assertSame([], glob($this->cache . '/*.php') ?: []);
    }

    public function testTemplatePathTraversalIsRejected(): void
    {
        file_put_contents($this->views . '/safe.twide', 'safe');
        self::assertFalse($this->engine()->exists('../safe'));

        $this->expectException(\InvalidArgumentException::class);
        $this->engine()->render('../safe');
    }
}
