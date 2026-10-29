<?php

declare(strict_types=1);

namespace Astral\Twide\Tests\Integration;

use Astral\Twide\Twide;
use PHPUnit\Framework\TestCase;

final class TwideCompositionTest extends TestCase
{
    private string $views;
    private string $cache;

    protected function setUp(): void
    {
        $id = bin2hex(random_bytes(5));
        $this->views = sys_get_temp_dir() . '/twide-composition-views-' . $id;
        $this->cache = sys_get_temp_dir() . '/twide-composition-cache-' . $id;
        mkdir($this->views, 0770, true);

        file_put_contents(
            $this->views . '/layout.twide',
            '<title>@yield(\'title\', \'Default title\')</title><main>@yield(\'content\')</main>'
        );
        file_put_contents(
            $this->views . '/child.twide',
            "@extends('layout')\n@section('title'){{ pageTitle }}@endsection\n@section('content')<h1>{{ heading }}</h1>@include('partial')@endsection"
        );
        file_put_contents($this->views . '/partial.twide', '<p>{{ message }}</p>');
        file_put_contents($this->views . '/default.twide', '@extends(\'layout\')@section(\'content\')Body@endsection');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->views . '/*') ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
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

    public function testChildTemplateFillsLayoutSectionsAndIncludesPartial(): void
    {
        $html = $this->engine()->render('child', [
            'pageTitle' => 'Profile',
            'heading' => 'Ada',
            'message' => 'Welcome',
        ]);

        self::assertSame('<title>Profile</title><main><h1>Ada</h1><p>Welcome</p></main>', $html);
    }

    public function testYieldUsesFallbackWhenSectionIsMissing(): void
    {
        self::assertSame('<title>Default title</title><main>Body</main>', $this->engine()->render('default'));
    }

    public function testCircularIncludeIsRejected(): void
    {
        file_put_contents($this->views . '/cycle-a.twide', "@include('cycle-b')");
        file_put_contents($this->views . '/cycle-b.twide', "@include('cycle-a')");

        $this->expectException(\Astral\Twide\Exceptions\TwideException::class);
        $this->expectExceptionMessage('Circular template inclusion');
        $this->engine()->render('cycle-a');
    }
}
