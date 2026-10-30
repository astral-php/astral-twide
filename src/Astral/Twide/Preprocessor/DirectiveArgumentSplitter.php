<?php

declare(strict_types=1);

namespace Astral\Twide\Preprocessor;

use Astral\Twide\Exceptions\TwideException;

/**
 * Splits directive argument lists on top-level commas (quotes / () / [] aware).
 */
final class DirectiveArgumentSplitter
{
    /**
     * @return list<string>
     */
    public function split(string $arguments): array
    {
        $arguments = trim($arguments);
        if ($arguments === '') {
            return [];
        }

        $parts = [];
        $length = strlen($arguments);
        $start = 0;
        $depthParen = 0;
        $depthBracket = 0;
        $quote = null;
        $escaped = false;

        for ($i = 0; $i < $length; $i++) {
            $char = $arguments[$i];
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
            if ($char === '(') {
                $depthParen++;
                continue;
            }
            if ($char === ')') {
                $depthParen--;
                if ($depthParen < 0) {
                    throw new TwideException('Unbalanced ")" in directive arguments.');
                }
                continue;
            }
            if ($char === '[') {
                $depthBracket++;
                continue;
            }
            if ($char === ']') {
                $depthBracket--;
                if ($depthBracket < 0) {
                    throw new TwideException('Unbalanced "]" in directive arguments.');
                }
                continue;
            }
            if ($char === ',' && $depthParen === 0 && $depthBracket === 0) {
                $parts[] = trim(substr($arguments, $start, $i - $start));
                $start = $i + 1;
            }
        }

        if ($quote !== null) {
            throw new TwideException('Unclosed string literal in directive arguments.');
        }
        if ($depthParen !== 0 || $depthBracket !== 0) {
            throw new TwideException('Unbalanced brackets in directive arguments.');
        }

        $parts[] = trim(substr($arguments, $start));

        return array_values(array_filter($parts, static fn (string $part): bool => $part !== ''));
    }
}
