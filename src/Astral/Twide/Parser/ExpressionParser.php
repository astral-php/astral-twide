<?php

declare(strict_types=1);

namespace Astral\Twide\Parser;

use Astral\Twide\Exceptions\TwideException;

/**
 * Pratt parser for Twide expressions.
 *
 * AST nodes are plain arrays to keep the first implementation lightweight.
 */
final class ExpressionParser
{
    /** @var list<array{type:string,value:mixed,offset:int}> */
    private array $tokens = [];
    private int $position = 0;

    public const MAX_EXPRESSION_BYTES = 16_384;
    public const MAX_TOKENS = 2_048;

    /**
     * @return array<string, mixed>
     */
    public function parse(string $expression): array
    {
        if (strlen($expression) > self::MAX_EXPRESSION_BYTES) {
            throw new TwideException(sprintf(
                'Expression exceeds the maximum size of %d bytes.',
                self::MAX_EXPRESSION_BYTES
            ));
        }

        $this->tokens = $this->lex($expression);
        $this->position = 0;
        $ast = $this->parseExpression(0);
        if ($this->peek()['type'] !== 'eof') {
            throw new TwideException('Unexpected token "' . $this->peek()['value'] . '" in expression.');
        }
        return $ast;
    }

    /** @return list<array{type:string,value:mixed,offset:int}> */
    private function lex(string $source): array
    {
        $tokens = [];
        $length = strlen($source);
        $i = 0;
        $multi = ['===', '!==', '??', '==', '!=', '<=', '>=', '&&', '||', '**'];
        while ($i < $length) {
            if (ctype_space($source[$i])) {
                $i++;
                continue;
            }
            $start = $i;
            $char = $source[$i];
            if ($char === '"' || $char === "'") {
                $quote = $char;
                $i++;
                $value = '';
                while ($i < $length && $source[$i] !== $quote) {
                    if ($source[$i] === '\\' && $i + 1 < $length) {
                        $i++;
                        $esc = $source[$i];
                        $value .= match ($esc) {
                            'n' => "\n",
                            'r' => "\r",
                            't' => "\t",
                            default => $esc,
                        };
                        $i++;
                        continue;
                    }
                    $value .= $source[$i++];
                }
                if ($i >= $length) {
                    throw new TwideException('Unclosed string literal in expression.');
                }
                $i++;
                $tokens[] = ['type' => 'literal', 'value' => $value, 'offset' => $start];
                continue;
            }
            if (ctype_digit($char)) {
                if (!preg_match('/\G\d+(?:\.\d+)?/A', $source, $number, 0, $i)) {
                    throw new TwideException(sprintf('Invalid numeric literal at offset %d.', $start));
                }
                $raw = $number[0];
                $i += strlen($raw);
                // Reject malformed chained decimals instead of tokenizing them ambiguously.
                if ($i < $length && $source[$i] === '.' && $i + 1 < $length && ctype_digit($source[$i + 1])) {
                    throw new TwideException('Invalid numeric literal near offset ' . $start . '.');
                }
                $value = str_contains($raw, '.') ? (float) $raw : (int) $raw;
                if (is_float($value) && !is_finite($value)) {
                    throw new TwideException('Numeric literal is outside the supported range.');
                }
                $tokens[] = ['type' => 'literal', 'value' => $value, 'offset' => $start];
                continue;
            }
            if (preg_match('/[A-Za-z_][A-Za-z0-9_]*/A', $source, $m, 0, $i)) {
                $i += strlen($m[0]);
                $word = $m[0];
                if (in_array($word, ['true', 'false', 'null'], true)) {
                    $value = match ($word) {
                        'true' => true,
                        'false' => false,
                        default => null,
                    };
                    $tokens[] = ['type' => 'literal', 'value' => $value, 'offset' => $start];
                } else {
                    $tokens[] = ['type' => 'identifier', 'value' => $word, 'offset' => $start];
                }
                continue;
            }
            $matched = null;
            foreach ($multi as $operator) {
                if (substr($source, $i, strlen($operator)) === $operator) {
                    $matched = $operator;
                    break;
                }
            }
            if ($matched !== null) {
                $tokens[] = ['type' => 'operator', 'value' => $matched, 'offset' => $i];
                $i += strlen($matched);
                continue;
            }
            if (str_contains('+-*/%()[]{}.,?:|!<>', $char)) {
                $tokens[] = ['type' => 'operator', 'value' => $char, 'offset' => $i++];
                continue;
            }
            throw new TwideException(sprintf('Unexpected character "%s" in expression at offset %d.', $char, $i));
        }
        if (count($tokens) >= self::MAX_TOKENS) {
            throw new TwideException(sprintf(
                'Expression exceeds the maximum of %d tokens.',
                self::MAX_TOKENS
            ));
        }
        $tokens[] = ['type' => 'eof', 'value' => '', 'offset' => $length];
        return $tokens;
    }

    /** @return array<string, mixed> */
    private function parseExpression(int $minBindingPower): array
    {
        $token = $this->next();
        if ($token['type'] === 'literal') {
            $left = ['type' => 'literal', 'value' => $token['value']];
        } elseif ($token['type'] === 'identifier') {
            $name = (string) $token['value'];
            if ($this->accept('(')) {
                $args = $this->parseArguments(')');
                $left = ['type' => 'call', 'name' => $name, 'args' => $args];
            } else {
                $left = ['type' => 'variable', 'name' => $name];
            }
        } elseif ($token['value'] === '(') {
            $left = $this->parseExpression(0);
            $this->expect(')');
        } elseif (in_array($token['value'], ['!', '-', '+'], true)) {
            $left = ['type' => 'unary', 'operator' => $token['value'], 'value' => $this->parseExpression(80)];
        } elseif ($token['value'] === '[') {
            $items = [];
            if (!$this->accept(']')) {
                do {
                    $items[] = $this->parseExpression(0);
                } while ($this->accept(','));
                $this->expect(']');
            }
            $left = ['type' => 'array', 'items' => $items];
        } else {
            throw new TwideException('Unexpected token "' . $token['value'] . '" at start of expression.');
        }

        while (true) {
            $token = $this->peek();
            $op = (string) $token['value'];

            // Property and index access bind tighter than all binary operators.
            if ($op === '.' || $op === '[') {
                if (100 < $minBindingPower) {
                    break;
                }
                $this->next();
                if ($op === '.') {
                    $property = $this->next();
                    if ($property['type'] !== 'identifier') {
                        throw new TwideException('Expected property name after ".".');
                    }
                    $left = ['type' => 'get', 'target' => $left, 'key' => ['type' => 'literal', 'value' => $property['value']]];
                } else {
                    $key = $this->parseExpression(0);
                    $this->expect(']');
                    $left = ['type' => 'get', 'target' => $left, 'key' => $key];
                }
                continue;
            }

            // Filters: value | filterName or value | filterName(args...)
            if ($op === '|') {
                if (10 < $minBindingPower) {
                    break;
                }
                $this->next();
                $filter = $this->next();
                if ($filter['type'] !== 'identifier') {
                    throw new TwideException('Expected filter name after "|".');
                }
                $args = [];
                if ($this->accept('(')) {
                    $args = $this->parseArguments(')');
                }
                $left = ['type' => 'filter', 'name' => $filter['value'], 'value' => $left, 'args' => $args];
                continue;
            }

            [$leftBp, $rightBp] = $this->bindingPower($op);
            if ($leftBp < $minBindingPower) {
                break;
            }
            if ($leftBp === 0) {
                break;
            }
            $this->next();
            if ($op === '?') {
                $yes = $this->parseExpression(0);
                $this->expect(':');
                $no = $this->parseExpression($rightBp);
                $left = ['type' => 'ternary', 'condition' => $left, 'yes' => $yes, 'no' => $no];
            } else {
                $right = $this->parseExpression($rightBp);
                $left = ['type' => 'binary', 'operator' => $op, 'left' => $left, 'right' => $right];
            }
        }

        return $left;
    }

    /** @return list<array<string, mixed>> */
    private function parseArguments(string $closing): array
    {
        $args = [];
        if ($this->accept($closing)) {
            return $args;
        }
        do {
            $args[] = $this->parseExpression(0);
        } while ($this->accept(','));
        $this->expect($closing);
        return $args;
    }

    /** @return array{0:int,1:int} */
    private function bindingPower(string $op): array
    {
        return match ($op) {
            '?' => [5, 5],
            '??' => [15, 15],
            '||' => [20, 21],
            '&&' => [25, 26],
            '==', '!=', '===', '!==', '<', '>', '<=', '>=' => [30, 31],
            '+' , '-' => [40, 41],
            '*', '/', '%' => [50, 51],
            '**' => [60, 60],
            default => [0, 0],
        };
    }

    private function accept(string $value): bool
    {
        if ($this->peek()['value'] !== $value) {
            return false;
        }
        $this->position++;
        return true;
    }

    private function expect(string $value): void
    {
        if (!$this->accept($value)) {
            throw new TwideException('Expected "' . $value . '" in expression, got "' . $this->peek()['value'] . '".');
        }
    }

    /** @return array{type:string,value:mixed,offset:int} */
    private function next(): array
    {
        return $this->tokens[$this->position++] ?? end($this->tokens);
    }

    /** @return array{type:string,value:mixed,offset:int} */
    private function peek(): array
    {
        return $this->tokens[$this->position] ?? end($this->tokens);
    }
}
