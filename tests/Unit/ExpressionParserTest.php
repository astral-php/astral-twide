<?php

declare(strict_types=1);

namespace Astral\Twide\Tests\Unit;

use Astral\Twide\Parser\ExpressionParser;
use Astral\Twide\Exceptions\TwideException;
use PHPUnit\Framework\TestCase;

final class ExpressionParserTest extends TestCase
{
    public function testParsesPropertyAndBinaryExpression(): void
    {
        $ast = (new ExpressionParser())->parse('user.age >= 18 && user.active');

        self::assertSame('binary', $ast['type']);
        self::assertSame('&&', $ast['operator']);
        self::assertSame('binary', $ast['left']['type']);
        self::assertSame('>=', $ast['left']['operator']);
    }

    public function testParsesFunctionAndFilter(): void
    {
        $ast = (new ExpressionParser())->parse('title | upper');

        self::assertSame('filter', $ast['type']);
        self::assertSame('upper', $ast['name']);
        self::assertSame('variable', $ast['value']['type']);
    }
    public function testRejectsMalformedNumericLiteral(): void
    {
        $this->expectException(TwideException::class);
        (new ExpressionParser())->parse('value + 1.2.3');
    }

    public function testRejectsOversizedExpression(): void
    {
        $this->expectException(TwideException::class);
        (new ExpressionParser())->parse(str_repeat('a', ExpressionParser::MAX_EXPRESSION_BYTES + 1));
    }

}
