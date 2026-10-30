<?php

declare(strict_types=1);

namespace Astral\Twide\Tests\Integration;

use Astral\Twide\Exceptions\TwideException;
use Astral\Twide\Twide;
use PHPUnit\Framework\TestCase;

final class TwideConditionalsExtraTest extends TestCase
{
    private string $views;
    private string $cache;

    protected function setUp(): void
    {
        $id = bin2hex(random_bytes(5));
        $this->views = sys_get_temp_dir() . '/twide-cond-views-' . $id;
        $this->cache = sys_get_temp_dir() . '/twide-cond-cache-' . $id;
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

    public function testIssetRendersWhenVariableDefinedAndNotNull(): void
    {
        file_put_contents($this->views . '/isset.twide', '@isset(user)YES@endisset');
        self::assertSame('YES', $this->engine()->render('isset', ['user' => 'Ada']));
        self::assertSame('', $this->engine()->render('isset', []));
        self::assertSame('', $this->engine()->render('isset', ['user' => null]));
    }

    public function testIssetSupportsNestedPath(): void
    {
        file_put_contents($this->views . '/isset-path.twide', '@isset(user.name){{ user.name }}@endisset');
        self::assertSame('Ada', $this->engine()->render('isset-path', [
            'user' => ['name' => 'Ada'],
        ]));
        self::assertSame('', $this->engine()->render('isset-path', [
            'user' => ['email' => 'a@b.c'],
        ]));
    }

    public function testEmptyDirective(): void
    {
        file_put_contents($this->views . '/empty.twide', '@empty(users)NONE@endempty');
        self::assertSame('NONE', $this->engine()->render('empty', ['users' => []]));
        self::assertSame('NONE', $this->engine()->render('empty', []));
        self::assertSame('', $this->engine()->render('empty', ['users' => [1]]));
    }

    public function testSwitchCaseDefaultAndBreak(): void
    {
        file_put_contents(
            $this->views . '/switch.twide',
            "@switch(status)@case('actif')ON@break@case('inactif')OFF@break@default?@endswitch"
        );
        self::assertSame('ON', $this->engine()->render('switch', ['status' => 'actif']));
        self::assertSame('OFF', $this->engine()->render('switch', ['status' => 'inactif']));
        self::assertSame('?', $this->engine()->render('switch', ['status' => 'other']));
    }

    public function testContinueAndBreakInsideFor(): void
    {
        file_put_contents(
            $this->views . '/loop-flow.twide',
            '@for(n in nums)@if(n == 2)@continue@endif@if(n == 4)@break@endif{{ n }};@endfor'
        );
        self::assertSame('1;3;', $this->engine()->render('loop-flow', [
            'nums' => [1, 2, 3, 4, 5],
        ]));
    }

    public function testBreakOutsideLoopIsRejectedWithLocation(): void
    {
        file_put_contents($this->views . '/bad-break.twide', '@break');
        $this->expectException(TwideException::class);
        $this->expectExceptionMessage('template "bad-break"');
        $this->expectExceptionMessage('@break is only allowed');
        $this->engine()->render('bad-break');
    }

    public function testMissingEndissetReportsTemplateAndLine(): void
    {
        file_put_contents($this->views . '/bad-isset.twide', "@isset(user)\nYES");
        $this->expectException(TwideException::class);
        $this->expectExceptionMessage('Missing @endisset');
        $this->expectExceptionMessage('template "bad-isset"');
        $this->engine()->render('bad-isset');
    }

    public function testBareEmptyWithoutExpressionIsRejectedClearly(): void
    {
        file_put_contents($this->views . '/bare-empty.twide', '@empty NONE@endempty');
        $this->expectException(TwideException::class);
        $this->expectExceptionMessage('Standalone @empty requires an expression');
        $this->engine()->render('bare-empty');
    }
}
