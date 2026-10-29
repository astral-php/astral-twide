<?php

declare(strict_types=1);

namespace Astral\Twide\Tests\Integration;

use Astral\Twide\Twide;
use PHPUnit\Framework\TestCase;

final class TwideRenderTest extends TestCase
{
    private string $views;
    private string $cache;

    protected function setUp(): void
    {
        $id = bin2hex(random_bytes(5));
        $this->views = sys_get_temp_dir() . '/twide-views-' . $id;
        $this->cache = sys_get_temp_dir() . '/twide-cache-' . $id;
        mkdir($this->views, 0770, true);
        file_put_contents($this->views . '/hello.twide', '<p>{{ user.name }}</p>');
        file_put_contents($this->views . '/loop.twide', '@for(user in users){{ user.name }};@endfor');
        file_put_contents($this->views . '/if.twide', '@if(active)YES@else NO@endif');
        file_put_contents($this->views . '/filter.twide', '{{ name | upper }}');
        file_put_contents($this->views . '/function.twide', '{{ greet(name) }}');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->views . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->views);
        foreach (glob($this->cache . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->cache);
    }

    private function engine(): Twide
    {
        return new Twide(['views' => $this->views, 'cache' => $this->cache]);
    }

    public function testEscapesHtmlByDefault(): void
    {
        self::assertSame('<p>&lt;script&gt;&amp;</p>', $this->engine()->render('hello', [
            'user' => ['name' => '<script>&'],
        ]));
    }

    public function testRendersForLoop(): void
    {
        self::assertSame('Ada;Lin;', $this->engine()->render('loop', [
            'users' => [['name' => 'Ada'], ['name' => 'Lin']],
        ]));
    }

    public function testRendersIfElse(): void
    {
        self::assertSame(' NO', $this->engine()->render('if', ['active' => false]));
    }

    public function testAppliesFilter(): void
    {
        self::assertSame('ADA', $this->engine()->render('filter', ['name' => 'Ada']));
    }

    public function testCustomFunction(): void
    {
        $engine = $this->engine();
        $engine->addFunction('greet', static fn (string $name): string => 'Hi ' . $name);
        self::assertSame('Hi Ada', $engine->render('function', ['name' => 'Ada']));
    }
}
