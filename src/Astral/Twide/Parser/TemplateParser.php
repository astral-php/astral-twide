<?php

declare(strict_types=1);

namespace Astral\Twide\Parser;

use Astral\Twide\Exceptions\TwideException;
use Astral\Twide\Lexer\Token;
use Astral\Twide\Lexer\TemplateLexer;

/**
 * Parses the first template grammar: text, echoes, @if and @for blocks.
 *
 * AST nodes are arrays; later phases may replace them with value objects
 * without changing the public template syntax.
 */
final class TemplateParser
{
    /** @var list<Token> */
    private array $tokens = [];
    private int $position = 0;
    private ExpressionParser $expressions;

    public function __construct(
        private readonly TemplateLexer $lexer = new TemplateLexer(),
        ?ExpressionParser $expressions = null,
    ) {
        $this->expressions = $expressions ?? new ExpressionParser();
    }

    /** @return list<array<string, mixed>> */
    public function parse(string $source): array
    {
        $this->tokens = $this->lexer->tokenize($source);
        $this->position = 0;
        [$nodes, $stop] = $this->parseNodes([]);
        if ($stop !== null) {
            throw $this->error('Unexpected closing directive @' . $stop['name'] . '.', $stop['token']);
        }
        return $nodes;
    }

    /**
     * @param list<string> $stopNames
     * @return array{0:list<array<string,mixed>>,1:?array{name:string,argument:string,token:Token}}
     */
    private function parseNodes(array $stopNames): array
    {
        $nodes = [];
        while ($this->peek()->type !== 'eof') {
            $token = $this->next();
            if ($token->type === 'text') {
                $nodes[] = ['type' => 'text', 'value' => $token->value];
            } elseif ($token->type === 'echo' || $token->type === 'raw_echo') {
                $nodes[] = [
                    'type' => $token->type,
                    'expression' => $this->expressions->parse($token->value),
                    'token' => $token,
                ];
            } elseif ($token->type === 'directive') {
                [$name, $argument] = array_pad(explode("\0", $token->value, 2), 2, '');
                if (in_array($name, $stopNames, true)) {
                    return [$nodes, ['name' => $name, 'argument' => $argument, 'token' => $token]];
                }
                if ($name === 'if') {
                    $nodes[] = $this->parseIf($argument, $token);
                } elseif ($name === 'for') {
                    $nodes[] = $this->parseFor($argument, $token);
                } elseif (in_array($name, ['else', 'elseif', 'endif', 'endfor', 'empty'], true)) {
                    throw $this->error('Unexpected directive @' . $name . '.', $token);
                } else {
                    throw $this->error('Unknown directive @' . $name . '.', $token);
                }
            }
        }
        return [$nodes, null];
    }

    /** @return array<string, mixed> */
    private function parseIf(string $argument, Token $token): array
    {
        if ($argument === '') {
            throw $this->error('@if requires an expression.', $token);
        }
        $branches = [];
        $condition = $this->expressions->parse($argument);
        [$body, $stop] = $this->parseNodes(['elseif', 'else', 'endif']);
        $branches[] = ['condition' => $condition, 'body' => $body];

        while ($stop !== null && $stop['name'] === 'elseif') {
            if ($stop['argument'] === '') {
                throw $this->error('@elseif requires an expression.', $stop['token']);
            }
            $condition = $this->expressions->parse($stop['argument']);
            [$body, $stop] = $this->parseNodes(['elseif', 'else', 'endif']);
            $branches[] = ['condition' => $condition, 'body' => $body];
        }

        $elseBody = [];
        if ($stop !== null && $stop['name'] === 'else') {
            [$elseBody, $stop] = $this->parseNodes(['endif']);
        }
        if ($stop === null || $stop['name'] !== 'endif') {
            throw $this->error('Missing @endif for @if.', $token);
        }

        return ['type' => 'if', 'branches' => $branches, 'else' => $elseBody, 'token' => $token];
    }

    /** @return array<string, mixed> */
    private function parseFor(string $argument, Token $token): array
    {
        if (!preg_match('/^\s*([A-Za-z_][A-Za-z0-9_]*)\s+in\s+(.+)$/s', $argument, $match)) {
            throw $this->error('Expected @for(item in expression).', $token);
        }

        if ($match[1] === 'loop') {
            throw $this->error('The variable name "loop" is reserved for loop metadata.', $token);
        }

        $expression = $this->expressions->parse(trim($match[2]));
        [$body, $stop] = $this->parseNodes(['endfor']);
        if ($stop === null || $stop['name'] !== 'endfor') {
            throw $this->error('Missing @endfor for @for.', $token);
        }

        return [
            'type' => 'for',
            'variable' => $match[1],
            'expression' => $expression,
            'body' => $body,
            'token' => $token,
        ];
    }

    private function peek(): Token
    {
        return $this->tokens[$this->position] ?? end($this->tokens);
    }

    private function next(): Token
    {
        return $this->tokens[$this->position++] ?? end($this->tokens);
    }

    private function error(string $message, Token $token): TwideException
    {
        return new TwideException(sprintf(
            '%s (line %d, column %d).',
            $message,
            $token->line,
            $token->column
        ));
    }
}
