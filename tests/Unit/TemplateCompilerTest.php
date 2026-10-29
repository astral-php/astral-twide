<?php

declare(strict_types=1);

namespace Astral\Twide\Tests\Unit;

use Astral\Twide\Compiler\TemplateCompiler;
use Astral\Twide\Exceptions\TwideException;
use Astral\Twide\Lexer\TemplateLexer;
use PHPUnit\Framework\TestCase;

final class TemplateCompilerTest extends TestCase
{
    public function testCompilesEchoAndCondition(): void
    {
        $compiled = (new TemplateCompiler())->compile('Hello {{ name }} @if(active)yes@endif');

        self::assertStringContainsString('$__twide_runtime->escape(', $compiled);
        self::assertStringContainsString('if (', $compiled);
        self::assertStringContainsString('return $__twide_output;', $compiled);
    }

    public function testCompiledCodeHasNoEval(): void
    {
        $compiled = (new TemplateCompiler())->compile('{{ user.name }}');

        self::assertStringNotContainsString('eval(', $compiled);
    }
    public function testRejectsOversizedTemplate(): void
    {
        $this->expectException(TwideException::class);
        (new TemplateLexer())->tokenize(str_repeat('x', TemplateLexer::MAX_TEMPLATE_BYTES + 1));
    }

}
