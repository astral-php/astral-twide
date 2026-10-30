<?php

declare(strict_types=1);

namespace Astral\Twide\Tests\Integration;

use Astral\Twide\Exceptions\TwideException;
use Astral\Twide\Twide;
use PHPUnit\Framework\TestCase;

final class TwideIncludeEachTest extends TestCase
{
    private string $views;
    private string $cache;

    protected function setUp(): void
    {
        $id = bin2hex(random_bytes(5));
        $this->views = sys_get_temp_dir() . '/twide-inc-views-' . $id;
        $this->cache = sys_get_temp_dir() . '/twide-inc-cache-' . $id;
        mkdir($this->views, 0770, true);
        mkdir($this->views . '/user', 0770, true);
        mkdir($this->views . '/partials', 0770, true);
    }

    protected function tearDown(): void
    {
        foreach (['/user', '/partials', ''] as $sub) {
            $dir = $this->views . $sub;
            foreach (glob($dir . '/*') ?: [] as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
            if ($sub !== '') {
                @rmdir($dir);
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

    public function testIncludeWithArrayLiteralMergesContextAndRestores(): void
    {
        file_put_contents($this->views . '/partials/input.twide', '{{ type }}:{{ name }}');
        file_put_contents(
            $this->views . '/page.twide',
            "OUT:{{ name }}|@include('partials/input' with ['type' => 'text', 'name' => 'email'])|AFTER:{{ name }}"
        );

        self::assertSame(
            'OUT:keep|text:email|AFTER:keep',
            $this->engine()->render('page', ['name' => 'keep'])
        );
    }

    public function testIncludeWithVariableObjectPublicProps(): void
    {
        file_put_contents($this->views . '/user/profile.twide', '{{ name }}<{{ email }}>');
        file_put_contents($this->views . '/show.twide', "@include('user/profile' with user)");

        $user = new class {
            public string $name = 'Ada';
            public string $email = 'ada@example.test';
        };

        self::assertSame('Ada<ada@example.test>', $this->engine()->render('show', ['user' => $user]));
    }

    public function testEachRendersPartialPerItem(): void
    {
        file_put_contents($this->views . '/user/card.twide', '[{{ user.name }}]');
        file_put_contents($this->views . '/list.twide', "@each('user/card', users, 'user')");

        self::assertSame('[Ada][Lin]', $this->engine()->render('list', [
            'users' => [
                ['name' => 'Ada'],
                ['name' => 'Lin'],
            ],
        ]));
    }

    public function testEachEmptyTemplate(): void
    {
        file_put_contents($this->views . '/user/card.twide', '[{{ user.name }}]');
        file_put_contents($this->views . '/partials/none.twide', 'EMPTY');
        file_put_contents(
            $this->views . '/list.twide',
            "@each('user/card', users, 'user', 'partials/none')"
        );

        self::assertSame('EMPTY', $this->engine()->render('list', ['users' => []]));
    }

    public function testEachRawEmptyLiteralIsEscaped(): void
    {
        file_put_contents($this->views . '/user/card.twide', 'x');
        file_put_contents(
            $this->views . '/list.twide',
            "@each('user/card', users, 'user', 'raw|<b>none</b>')"
        );

        self::assertSame('&lt;b&gt;none&lt;/b&gt;', $this->engine()->render('list', ['users' => []]));
    }

    public function testRejectsDynamicIncludeName(): void
    {
        file_put_contents($this->views . '/bad.twide', '@include(templateName)');
        $this->expectException(TwideException::class);
        $this->expectExceptionMessage('static quoted string');
        $this->engine()->render('bad', ['templateName' => 'x']);
    }

    public function testRejectsInvalidEachArity(): void
    {
        file_put_contents($this->views . '/bad-each.twide', "@each('user/card', users)");
        $this->expectException(TwideException::class);
        $this->expectExceptionMessage('@each expects');
        $this->engine()->render('bad-each', ['users' => []]);
    }
}
