<?php

declare(strict_types=1);

namespace Astral\Twide\Tests\Unit;

use Astral\Twide\Preprocessor\DirectiveArgumentSplitter;
use PHPUnit\Framework\TestCase;

final class DirectiveArgumentSplitterTest extends TestCase
{
    public function testSplitsRespectingQuotesAndBrackets(): void
    {
        $parts = (new DirectiveArgumentSplitter())->split(
            "'user/card', users|arr_only(['id', 'name']), 'user', 'raw|none, yet'"
        );

        self::assertSame(
            ["'user/card'", "users|arr_only(['id', 'name'])", "'user'", "'raw|none, yet'"],
            $parts
        );
    }
}
