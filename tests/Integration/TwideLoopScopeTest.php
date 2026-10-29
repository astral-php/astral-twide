<?php

declare(strict_types=1);

namespace Astral\Twide\Tests\Integration;

use Astral\Twide\Twide;
use PHPUnit\Framework\TestCase;

final class TwideLoopScopeTest extends TestCase
{
    private string $views;
    private string $cache;

    protected function setUp(): void
    {
        $id = bin2hex(random_bytes(5));
        $this->views = sys_get_temp_dir() . '/twide-loop-views-' . $id;
        $this->cache = sys_get_temp_dir() . '/twide-loop-cache-' . $id;
        mkdir($this->views, 0770, true);
        file_put_contents($this->views . '/metadata.twide', '@for(item in items){{ loop.index }}:{{ loop.first }}:{{ loop.last }}:{{ item }};@endfor');
        file_put_contents($this->views . '/restore.twide', '{{ item }}|@for(item in items){{ item }}@endfor|{{ item }}');
        file_put_contents($this->views . '/nested.twide', '@for(item in items){{ item.name }}[@for(child in item.children){{ loop.parent.index }}.{{ loop.index }}={{ child }};@endfor]@endfor');
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

    public function testLoopMetadataIsAvailable(): void
    {
        self::assertSame(
            '1:1::a;2::1:b;',
            $this->engine()->render('metadata', ['items' => ['a', 'b']])
        );
    }

    public function testLoopVariableIsRestoredAfterLoop(): void
    {
        self::assertSame('outside|ab|outside', $this->engine()->render('restore', [
            'item' => 'outside',
            'items' => ['a', 'b'],
        ]));
    }

    public function testNestedLoopMetadataRetainsParent(): void
    {
        self::assertSame(
            'A[1.1=x;1.2=y;]B[2.1=z;]',
            $this->engine()->render('nested', [
                'items' => [
                    ['name' => 'A', 'children' => ['x', 'y']],
                    ['name' => 'B', 'children' => ['z']],
                ],
            ])
        );
    }
}
