<?php

declare(strict_types=1);

namespace Astral\Twide\Tests\Integration;

use Astral\Twide\Exceptions\TwideException;
use Astral\Twide\Twide;
use PHPUnit\Framework\TestCase;

final class TwideStacksTest extends TestCase
{
    private string $views;
    private string $cache;

    protected function setUp(): void
    {
        $id = bin2hex(random_bytes(5));
        $this->views = sys_get_temp_dir() . '/twide-stacks-views-' . $id;
        $this->cache = sys_get_temp_dir() . '/twide-stacks-cache-' . $id;
        mkdir($this->views, 0770, true);
        mkdir($this->views . '/partials', 0770, true);
        mkdir($this->views . '/components', 0770, true);

        file_put_contents($this->views . '/layout.twide', '<html><head>@stack(\'styles\')</head><body>@yield(\'content\')@stack(\'scripts\')</body></html>');
        file_put_contents($this->views . '/page.twide', "@extends('layout')\n@section('content')Main@include('partials/widget')@endsection");
        file_put_contents($this->views . '/partials/widget.twide', "@push('scripts')<script src=\"/widget.js\"></script>@endpushWidget");
        file_put_contents($this->views . '/components/card.twide', "@push('styles')<link rel=\"stylesheet\" href=\"/card.css\">@endpush<div>@yield('slot')</div>");
        file_put_contents($this->views . '/with-component.twide', "@extends('layout')@section('content')@component('components/card')Card body@endcomponent@endsection");
        file_put_contents($this->views . '/unknown.twide', 'Body@push(\'scripts\')unterminated');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->views . '/partials/*') ?: [] as $file) @unlink($file);
        foreach (glob($this->views . '/components/*') ?: [] as $file) @unlink($file);
        foreach (glob($this->views . '/*') ?: [] as $file) if (is_file($file)) @unlink($file);
        @rmdir($this->views . '/partials');
        @rmdir($this->views . '/components');
        @rmdir($this->views);
        foreach (glob($this->cache . '/*') ?: [] as $file) @unlink($file);
        @rmdir($this->cache);
    }

    private function engine(): Twide
    {
        return new Twide(['views' => $this->views, 'cache' => $this->cache]);
    }

    public function testPushInsideIncludedPartialIsPlacedAtLayoutStack(): void
    {
        self::assertSame(
            '<html><head></head><body>MainWidget<script src="/widget.js"></script></body></html>',
            $this->engine()->render('page')
        );
    }

    public function testPushInsideComponentIsPlacedAtLayoutStack(): void
    {
        self::assertSame(
            '<html><head><link rel="stylesheet" href="/card.css"></head><body><div>Card body</div></body></html>',
            $this->engine()->render('with-component')
        );
    }

    public function testUnclosedPushIsRejected(): void
    {
        $this->expectException(TwideException::class);
        $this->expectExceptionMessage('Every @push directive must have a matching @endpush.');
        $this->engine()->render('unknown');
    }
}
