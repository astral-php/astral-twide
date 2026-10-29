<?php

declare(strict_types=1);

namespace Astral\Twide\Tests\Integration;

use Astral\Twide\Exceptions\TwideException;
use Astral\Twide\Twide;
use PHPUnit\Framework\TestCase;

final class TwideComponentsTest extends TestCase
{
    private string $views;
    private string $cache;

    protected function setUp(): void
    {
        $id = bin2hex(random_bytes(5));
        $this->views = sys_get_temp_dir() . '/twide-components-views-' . $id;
        $this->cache = sys_get_temp_dir() . '/twide-components-cache-' . $id;
        mkdir($this->views, 0770, true);
        mkdir($this->views . '/components', 0770, true);

        file_put_contents(
            $this->views . '/components/card.twide',
            '<article><header>@yield(\'title\', \'Untitled\')</header><div>@yield(\'slot\')</div></article>'
        );
        file_put_contents(
            $this->views . '/page.twide',
            "@component('components/card')\n@slot('title'){{ title }}@endslot\n<p>{{ message }}</p>\n@endcomponent"
        );
        file_put_contents(
            $this->views . '/components/outer.twide',
            '<section>@yield(\'slot\')</section>'
        );
        file_put_contents(
            $this->views . '/nested.twide',
            "@component('components/outer')@component('components/card')@slot('title')Inner@endslot<span>Nested</span>@endcomponent@endcomponent"
        );
        file_put_contents(
            $this->views . '/broken.twide',
            "@component('components/card')No end"
        );
    }

    protected function tearDown(): void
    {
        foreach (glob($this->views . '/components/*') ?: [] as $file) {
            @unlink($file);
        }
        foreach (glob($this->views . '/*') ?: [] as $file) {
            if (is_file($file)) @unlink($file);
        }
        @rmdir($this->views . '/components');
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

    public function testNamedAndDefaultSlotsRenderInsideComponent(): void
    {
        $html = $this->engine()->render('page', [
            'title' => 'Profile',
            'message' => 'Hello',
        ]);

        self::assertSame(
            '<article><header>Profile</header><div>' . "\n    \n    " . '<p>Hello</p>' . "\n    " . '</div></article>',
            $html
        );
    }

    public function testNestedComponentsRender(): void
    {
        self::assertSame(
            '<section><article><header>Inner</header><div><span>Nested</span></div></article></section>',
            $this->engine()->render('nested')
        );
    }

    public function testUnclosedComponentIsRejected(): void
    {
        $this->expectException(TwideException::class);
        $this->expectExceptionMessage('Missing @endcomponent');
        $this->engine()->render('broken');
    }
}
