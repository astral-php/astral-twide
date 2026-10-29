<?php

declare(strict_types=1);

namespace Astral\Twide\Tests\Integration;

use Astral\Twide\Contracts\TemplateEngineInterface;
use Astral\Twide\Integration\TwideViewAdapter;
use PHPUnit\Framework\TestCase;

final class TwideViewAdapterTest extends TestCase
{
    public function testAdapterDelegatesRenderingAndExistenceChecks(): void
    {
        $engine = new class implements TemplateEngineInterface {
            public function render(string $template, array $data = []): string
            {
                return $template . ':' . ($data['value'] ?? '');
            }

            public function exists(string $template): bool
            {
                return $template === 'home';
            }
        };

        $adapter = new TwideViewAdapter($engine);
        self::assertSame('home:42', $adapter->renderView('home', ['value' => 42]));
        self::assertTrue($adapter->viewExists('home'));
        self::assertFalse($adapter->viewExists('missing'));
        self::assertSame($engine, $adapter->engine());
    }
}
