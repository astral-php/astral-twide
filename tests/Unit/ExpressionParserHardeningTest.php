<?php

declare(strict_types=1);

namespace Astral\Twide\Tests\Unit;

use Astral\Twide\Exceptions\TwideException;
use Astral\Twide\Parser\ExpressionParser;
use PHPUnit\Framework\TestCase;

final class ExpressionParserHardeningTest extends TestCase
{
    public function testRejectsUnknownCharactersInsteadOfSilentlyIgnoringThem(): void
    {
        $this->expectException(TwideException::class);
        (new ExpressionParser())->parse('name $ value');
    }

    public function testRejectsUnclosedStringLiteral(): void
    {
        $this->expectException(TwideException::class);
        $this->expectExceptionMessage('Unclosed string literal');
        (new ExpressionParser())->parse("'unfinished");
    }

    public function testRejectsTrailingUnexpectedTokens(): void
    {
        $this->expectException(TwideException::class);
        $this->expectExceptionMessage('Unexpected token');
        (new ExpressionParser())->parse('user.name another');
    }

    public function testParsesNullCoalescingAndTernaryExpressions(): void
    {
        $ast = (new ExpressionParser())->parse("nickname ?? (name ? name : 'Anonyme')");
        self::assertSame('binary', $ast['type']);
        self::assertSame('??', $ast['operator']);
        self::assertSame('ternary', $ast['right']['type']);
    }
}
