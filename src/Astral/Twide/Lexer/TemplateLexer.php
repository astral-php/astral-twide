<?php

declare(strict_types=1);

namespace Astral\Twide\Lexer;

use Astral\Twide\Exceptions\TwideException;

/**
 * Splits a template into text, echo, comment and directive tokens.
 *
 * This lexer deliberately does not evaluate expressions or generate PHP.
 */
final class TemplateLexer
{
    public const MAX_TEMPLATE_BYTES = 1_048_576;

    /**
     * Known directives (longest first). A match requires a non-identifier
     * boundary so @emptyNONE is tokenized as @empty + text "NONE".
     *
     * @var list<string>
     */
    private const DIRECTIVES = [
        'endcomponent', 'endforelse', 'endforeach', 'endsection', 'endswitch',
        'endisset', 'endempty', 'endwhile', 'endpush', 'endslot', 'endfor', 'endif',
        'component', 'foreach', 'forelse', 'extends', 'section', 'default', 'elseif',
        'continue', 'endscope', 'include', 'switch', 'isset', 'empty', 'while', 'break',
        'else', 'case', 'push', 'slot', 'stack', 'yield', 'scope', 'each', 'for', 'if', 'set',
    ];

    /** @return list<Token> */
    public function tokenize(string $source): array
    {
        if (strlen($source) > self::MAX_TEMPLATE_BYTES) {
            throw new TwideException(sprintf(
                'Template source exceeds the maximum size of %d bytes.',
                self::MAX_TEMPLATE_BYTES
            ));
        }

        $tokens = [];
        $length = strlen($source);
        $offset = 0;
        $textStart = 0;

        while ($offset < $length) {
            $kind = null;
            $openLength = 0;
            if (substr($source, $offset, 3) === '{{--') {
                $kind = 'comment';
                $openLength = 4;
            } elseif (substr($source, $offset, 2) === '{{') {
                $kind = 'echo';
                $openLength = 2;
            } elseif (substr($source, $offset, 2) === '{!') {
                $kind = 'raw_echo';
                $openLength = 2;
            } elseif ($source[$offset] === '@' && ($directiveMatch = $this->matchDirective($source, $offset)) !== null) {
                $kind = 'directive';
                $openLength = $directiveMatch['length'];
                $m = [1 => $directiveMatch['name']];
            }

            if ($kind === null) {
                $offset++;
                continue;
            }

            if ($offset > $textStart) {
                $tokens[] = $this->token('text', substr($source, $textStart, $offset - $textStart), $source, $textStart);
            }

            if ($kind === 'comment') {
                $end = strpos($source, '--}}', $offset + $openLength);
                if ($end === false) {
                    throw $this->syntaxError('Unclosed template comment.', $source, $offset);
                }
                $offset = $end + 4;
                $textStart = $offset;
                continue;
            }

            if ($kind === 'echo' || $kind === 'raw_echo') {
                $close = $kind === 'echo' ? '}}' : '!}';
                $bodyStart = $offset + $openLength;
                $end = $this->findClosingDelimiter($source, $bodyStart, $close);
                if ($end === null) {
                    throw $this->syntaxError('Unclosed echo expression.', $source, $offset);
                }
                $body = trim(substr($source, $bodyStart, $end - $bodyStart));
                if ($body === '') {
                    throw $this->syntaxError('Echo expression cannot be empty.', $source, $offset);
                }
                $tokens[] = $this->token($kind, $body, $source, $offset);
                $offset = $end + strlen($close);
                $textStart = $offset;
                continue;
            }

            $directiveName = substr($m[1], 0);
            $directiveEnd = $offset + $openLength;
            while ($directiveEnd < $length && ctype_space($source[$directiveEnd])) {
                $directiveEnd++;
            }
            $argument = '';
            $endOffset = $offset + $openLength;
            if ($directiveEnd < $length && $source[$directiveEnd] === '(') {
                $close = $this->findBalancedParenthesis($source, $directiveEnd);
                if ($close === null) {
                    throw $this->syntaxError("Unclosed argument list for @{$directiveName}.", $source, $offset);
                }
                $argument = trim(substr($source, $directiveEnd + 1, $close - $directiveEnd - 1));
                $endOffset = $close + 1;
            }

            $tokens[] = $this->token('directive', $directiveName . "\0" . $argument, $source, $offset);
            $offset = $endOffset;
            $textStart = $offset;
        }

        if ($textStart < $length) {
            $tokens[] = $this->token('text', substr($source, $textStart), $source, $textStart);
        }

        $tokens[] = $this->token('eof', '', $source, $length);
        return $tokens;
    }

    /**
     * Longest known directive prefix wins (e.g. @elseif before @else,
     * @empty before treating "@emptyNONE" as an unknown token).
     *
     * @return array{name:string,length:int}|null
     */
    private function matchDirective(string $source, int $offset): ?array
    {
        foreach (self::DIRECTIVES as $name) {
            $candidate = '@' . $name;
            $candidateLength = strlen($candidate);
            if (substr($source, $offset, $candidateLength) === $candidate) {
                return ['name' => $name, 'length' => $candidateLength];
            }
        }

        return null;
    }

    private function findClosingDelimiter(string $source, int $start, string $close): ?int
    {
        $quote = null;
        $escaped = false;
        $length = strlen($source);
        for ($i = $start; $i < $length; $i++) {
            $char = $source[$i];
            if ($quote !== null) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($char === '"' || $char === "'") {
                $quote = $char;
                continue;
            }
            if (substr($source, $i, strlen($close)) === $close) {
                return $i;
            }
        }
        return null;
    }

    private function findBalancedParenthesis(string $source, int $open): ?int
    {
        $depth = 0;
        $quote = null;
        $escaped = false;
        $length = strlen($source);
        for ($i = $open; $i < $length; $i++) {
            $char = $source[$i];
            if ($quote !== null) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }
        return null;
    }

    private function token(string $type, string $value, string $source, int $offset): Token
    {
        $before = substr($source, 0, $offset);
        $line = substr_count($before, "\n") + 1;
        $lastNewline = strrpos($before, "\n");
        $column = $lastNewline === false ? $offset + 1 : $offset - $lastNewline;
        return new Token($type, $value, $offset, $line, $column);
    }

    private function syntaxError(string $message, string $source, int $offset): TwideException
    {
        $before = substr($source, 0, $offset);
        $line = substr_count($before, "\n") + 1;
        $lastNewline = strrpos($before, "\n");
        $column = $lastNewline === false ? $offset + 1 : $offset - $lastNewline;
        return new TwideException(sprintf('%s (line %d, column %d).', $message, $line, $column));
    }
}
