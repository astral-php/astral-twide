<?php

declare(strict_types=1);

namespace Astral\Twide\Tests\Integration;

use Astral\Twide\Exceptions\TwideException;
use Astral\Twide\Twide;
use PHPUnit\Framework\TestCase;

final class TwideSecurityTest extends TestCase
{
    private string $views;
    private string $cache;

    protected function setUp(): void
    {
        $id = bin2hex(random_bytes(5));
        $this->views = sys_get_temp_dir() . '/twide-security-views-' . $id;
        $this->cache = sys_get_temp_dir() . '/twide-security-cache-' . $id;
        mkdir($this->views, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->views . '/*') ?: [] as $file) {
            if (is_file($file) || is_link($file)) {
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

    public function testNormalEchoEscapesHtmlCharacters(): void
    {
        file_put_contents($this->views . '/escape.twide', '{{ value }}');
        self::assertSame('&lt;script&gt;&quot;x&quot;&lt;/script&gt;', $this->engine()->render('escape', [
            'value' => '<script>"x"</script>',
        ]));
    }

    public function testRawEchoRejectsNonStringableValues(): void
    {
        file_put_contents($this->views . '/raw.twide', '{! value !}');
        $this->expectException(TwideException::class);
        $this->expectExceptionMessage('Raw output accepts only strings');
        $this->engine()->render('raw', ['value' => ['unsafe']]);
    }

    public function testUnmatchedPushDirectiveIsRejected(): void
    {
        file_put_contents($this->views . '/push.twide', "@push('scripts')<script></script>");
        $this->expectException(TwideException::class);
        $this->expectExceptionMessage('matching @endpush');
        $this->engine()->render('push');
    }

    public function testUnmatchedSlotDirectiveIsRejected(): void
    {
        file_put_contents($this->views . '/component.twide', "@component('card')@slot('title')Hello@endcomponent");
        file_put_contents($this->views . '/card.twide', '<section>@yield(\'title\')</section>');
        $this->expectException(TwideException::class);
        $this->expectExceptionMessage('matching @endslot');
        $this->engine()->render('component');
    }

    public function testCompiledCacheFileIsNotWorldReadable(): void
    {
        file_put_contents($this->views . '/simple.twide', 'hello');
        self::assertSame('hello', $this->engine()->render('simple'));
        $files = glob($this->cache . '/*.php') ?: [];
        self::assertCount(1, $files);
        // Windows does not honor Unix permission bits the same way; chmod(0600) is best-effort.
        if (PHP_OS_FAMILY !== 'Windows') {
            self::assertSame(0600, fileperms($files[0]) & 0777);
        } else {
            self::assertFileExists($files[0]);
        }
    }
}
