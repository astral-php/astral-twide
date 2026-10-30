<?php

declare(strict_types=1);

namespace Astral\Twide\Tests\Integration;

use Astral\Twide\Exceptions\TwideException;
use Astral\Twide\Twide;
use PHPUnit\Framework\TestCase;

final class TwideLoopsExtraTest extends TestCase
{
    private string $views;
    private string $cache;

    protected function setUp(): void
    {
        $id = bin2hex(random_bytes(5));
        $this->views = sys_get_temp_dir() . '/twide-loops-views-' . $id;
        $this->cache = sys_get_temp_dir() . '/twide-loops-cache-' . $id;
        mkdir($this->views, 0770, true);
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

    public function testForeachWithKeyAndFilterCondition(): void
    {
        file_put_contents(
            $this->views . '/foreach.twide',
            '@foreach(users as key => user if user.active)#{{ key }}:{{ user.name }};@endforeach'
        );
        self::assertSame('#0:Ada;#2:Lin;', $this->engine()->render('foreach', [
            'users' => [
                ['name' => 'Ada', 'active' => true],
                ['name' => 'Bob', 'active' => false],
                ['name' => 'Lin', 'active' => true],
            ],
        ]));
    }

    public function testForelseEmptyBranch(): void
    {
        file_put_contents(
            $this->views . '/forelse.twide',
            '@forelse(users as user){{ user }}@emptyNONE@endforelse'
        );
        self::assertSame('NONE', $this->engine()->render('forelse', ['users' => []]));
        self::assertSame('Ada', $this->engine()->render('forelse', ['users' => ['Ada']]));
    }

    public function testForRangeCountIterations(): void
    {
        file_put_contents($this->views . '/range.twide', '@for(i in 0 count 3){{ i }};@endfor');
        self::assertSame('0;1;2;', $this->engine()->render('range'));
    }

    public function testForRangeWithExpressionCount(): void
    {
        file_put_contents($this->views . '/range2.twide', '@for(p in 1 count total){{ p }};@endfor');
        self::assertSame('1;2;', $this->engine()->render('range2', ['total' => 2]));
    }

    public function testWhileWithRegisteredGuard(): void
    {
        file_put_contents($this->views . '/while.twide', '@while(cursor < max){{ cursor }};@set(cursor = cursor + 1)@endwhile');
        self::assertSame('0;1;', $this->engine()->render('while', ['cursor' => 0, 'max' => 2]));
    }

    public function testSetAssignsInTemplateScope(): void
    {
        file_put_contents($this->views . '/set.twide', "@set(title = 'Hello'){{ title }}");
        self::assertSame('Hello', $this->engine()->render('set'));
    }

    public function testInvalidForeachReportsLocation(): void
    {
        file_put_contents($this->views . '/bad-foreach.twide', '@foreach(users)x@endforeach');
        $this->expectException(TwideException::class);
        $this->expectExceptionMessage('Invalid @foreach syntax');
        $this->expectExceptionMessage('template "bad-foreach"');
        $this->engine()->render('bad-foreach');
    }
}
