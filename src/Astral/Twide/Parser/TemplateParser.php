<?php

declare(strict_types=1);

namespace Astral\Twide\Parser;

use Astral\Twide\Exceptions\TwideException;
use Astral\Twide\Lexer\Token;
use Astral\Twide\Lexer\TemplateLexer;

/**
 * Parses Twide templates into an AST (text, echoes, conditionals, loops, …).
 */
final class TemplateParser
{
    /** @var list<Token> */
    private array $tokens = [];
    private int $position = 0;
    private ExpressionParser $expressions;
    private string $templateName = 'inline';
    private int $loopDepth = 0;
    private int $switchDepth = 0;

    public function __construct(
        private readonly TemplateLexer $lexer = new TemplateLexer(),
        ?ExpressionParser $expressions = null,
    ) {
        $this->expressions = $expressions ?? new ExpressionParser();
    }

    /** @return list<array<string, mixed>> */
    public function parse(string $source, string $templateName = 'inline'): array
    {
        $this->templateName = $templateName;
        $this->tokens = $this->lexer->tokenize($source);
        $this->position = 0;
        $this->loopDepth = 0;
        $this->switchDepth = 0;
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
                $nodes[] = $this->parseDirective($name, $argument, $token);
            }
        }
        return [$nodes, null];
    }

    /** @return array<string, mixed> */
    private function parseDirective(string $name, string $argument, Token $token): array
    {
        return match ($name) {
            'if' => $this->parseIf($argument, $token),
            'for' => $this->parseFor($argument, $token),
            'foreach' => $this->parseForeach($argument, $token),
            'forelse' => $this->parseForelse($argument, $token),
            'while' => $this->parseWhile($argument, $token),
            'isset' => $this->parseIsset($argument, $token),
            'empty' => $this->parseEmpty($argument, $token),
            'switch' => $this->parseSwitch($argument, $token),
            'set' => $this->parseSet($argument, $token),
            'scope' => $this->parseScope($argument, $token),
            'break' => $this->parseBreak($token),
            'continue' => $this->parseContinue($token),
            'else', 'elseif', 'endif', 'endfor', 'endforeach', 'endforelse', 'endwhile',
            'endisset', 'endempty', 'endscope', 'case', 'default', 'endswitch'
                => throw $this->error('Unexpected directive @' . $name . '.', $token),
            default => throw $this->error('Unknown directive @' . $name . '.', $token),
        };
    }

    /** @return array<string, mixed> */
    private function parseIf(string $argument, Token $token): array
    {
        if ($argument === '') {
            throw $this->error('@if requires an expression (e.g. @if(user.active)).', $token);
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
        // Range: @for(i in 0 count 10) → 10 iterations starting at 0
        if (preg_match(
            '/^\s*([A-Za-z_][A-Za-z0-9_]*)\s+in\s+(.+?)\s+count\s+(.+)$/s',
            $argument,
            $match
        )) {
            return $this->finishLoopBlock([
                'type' => 'for_range',
                'variable' => $this->assertLoopVariable($match[1], $token),
                'start' => $this->expressions->parse(trim($match[2])),
                'count' => $this->expressions->parse(trim($match[3])),
                'token' => $token,
            ], 'endfor', 'Missing @endfor for @for.');
        }

        if (!preg_match('/^\s*([A-Za-z_][A-Za-z0-9_]*)\s+in\s+(.+)$/s', $argument, $match)) {
            throw $this->error(
                'Invalid @for syntax. Expected @for(item in items) or @for(i in start count n).',
                $token
            );
        }

        return $this->finishLoopBlock([
            'type' => 'for',
            'variable' => $this->assertLoopVariable($match[1], $token),
            'expression' => $this->expressions->parse(trim($match[2])),
            'token' => $token,
        ], 'endfor', 'Missing @endfor for @for.');
    }

    /** @return array<string, mixed> */
    private function parseForeach(string $argument, Token $token): array
    {
        $parsed = $this->parseForeachArguments($argument, $token, '@foreach');

        return $this->finishLoopBlock([
            'type' => 'foreach',
            'expression' => $parsed['expression'],
            'key' => $parsed['key'],
            'variable' => $parsed['variable'],
            'condition' => $parsed['condition'],
            'token' => $token,
        ], 'endforeach', 'Missing @endforeach for @foreach.');
    }

    /** @return array<string, mixed> */
    private function parseForelse(string $argument, Token $token): array
    {
        $parsed = $this->parseForeachArguments($argument, $token, '@forelse');
        if ($parsed['condition'] !== null) {
            throw $this->error('@forelse does not support an "if" clause; use @foreach or filter the collection.', $token);
        }

        $this->loopDepth++;
        [$body, $stop] = $this->parseNodes(['empty', 'endforelse']);
        $emptyBody = [];
        if ($stop !== null && $stop['name'] === 'empty') {
            if ($stop['argument'] !== '') {
                throw $this->error(
                    'Inside @forelse, use bare @empty (not @empty(...)). '
                    . 'For a standalone empty check, use @empty(expr) … @endempty outside @forelse.',
                    $stop['token']
                );
            }
            [$emptyBody, $stop] = $this->parseNodes(['endforelse']);
        }
        $this->loopDepth--;

        if ($stop === null || $stop['name'] !== 'endforelse') {
            throw $this->error('Missing @endforelse for @forelse.', $token);
        }

        return [
            'type' => 'forelse',
            'expression' => $parsed['expression'],
            'key' => $parsed['key'],
            'variable' => $parsed['variable'],
            'body' => $body,
            'empty' => $emptyBody,
            'token' => $token,
        ];
    }

    /** @return array<string, mixed> */
    private function parseWhile(string $argument, Token $token): array
    {
        if ($argument === '') {
            throw $this->error('@while requires a condition (e.g. @while(cursor < max)).', $token);
        }

        return $this->finishLoopBlock([
            'type' => 'while',
            'condition' => $this->expressions->parse($argument),
            'token' => $token,
        ], 'endwhile', 'Missing @endwhile for @while.');
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private function finishLoopBlock(array $node, string $endName, string $missingMessage): array
    {
        $this->loopDepth++;
        [$body, $stop] = $this->parseNodes([$endName]);
        $this->loopDepth--;
        if ($stop === null || $stop['name'] !== $endName) {
            throw $this->error($missingMessage, $node['token']);
        }
        $node['body'] = $body;

        return $node;
    }

    private function assertLoopVariable(string $name, Token $token): string
    {
        if ($name === 'loop') {
            throw $this->error('The variable name "loop" is reserved for loop metadata.', $token);
        }

        return $name;
    }

    /**
     * @return array{expression:array<string,mixed>,key:?string,variable:string,condition:?array<string,mixed>}
     */
    private function parseForeachArguments(string $argument, Token $token, string $directive): array
    {
        $argument = trim($argument);
        if ($argument === '') {
            throw $this->error(
                $directive . ' requires arguments (e.g. ' . $directive . '(users as user)).',
                $token
            );
        }

        $condition = null;
        $foreachPart = $argument;
        if (preg_match('/^(.+?)\s+if\s+(.+)$/s', $argument, $ifMatch)) {
            $foreachPart = trim($ifMatch[1]);
            $condition = $this->expressions->parse(trim($ifMatch[2]));
        }

        if (preg_match(
            '/^(.+?)\s+as\s+([A-Za-z_][A-Za-z0-9_]*)\s*=>\s*([A-Za-z_][A-Za-z0-9_]*)$/s',
            $foreachPart,
            $match
        )) {
            return [
                'expression' => $this->expressions->parse(trim($match[1])),
                'key' => $this->assertLoopVariable($match[2], $token),
                'variable' => $this->assertLoopVariable($match[3], $token),
                'condition' => $condition,
            ];
        }

        if (preg_match('/^(.+?)\s+as\s+([A-Za-z_][A-Za-z0-9_]*)$/s', $foreachPart, $match)) {
            return [
                'expression' => $this->expressions->parse(trim($match[1])),
                'key' => null,
                'variable' => $this->assertLoopVariable($match[2], $token),
                'condition' => $condition,
            ];
        }

        throw $this->error(
            'Invalid ' . $directive . ' syntax. Expected: items as item, items as key => item, '
            . 'optionally followed by "if condition".',
            $token
        );
    }

    /** @return array<string, mixed> */
    private function parseIsset(string $argument, Token $token): array
    {
        if ($argument === '') {
            throw $this->error('@isset requires a path (e.g. @isset(user) or @isset(user.name)).', $token);
        }
        $expression = $this->expressions->parse($argument);
        [$body, $stop] = $this->parseNodes(['endisset']);
        if ($stop === null || $stop['name'] !== 'endisset') {
            throw $this->error('Missing @endisset for @isset.', $token);
        }

        return ['type' => 'isset', 'expression' => $expression, 'body' => $body, 'token' => $token];
    }

    /** @return array<string, mixed> */
    private function parseEmpty(string $argument, Token $token): array
    {
        if ($argument === '') {
            throw $this->error(
                'Standalone @empty requires an expression (e.g. @empty(users) … @endempty). '
                . 'Bare @empty is reserved for @forelse (later phase).',
                $token
            );
        }
        $expression = $this->expressions->parse($argument);
        [$body, $stop] = $this->parseNodes(['endempty']);
        if ($stop === null || $stop['name'] !== 'endempty') {
            throw $this->error('Missing @endempty for @empty.', $token);
        }

        return ['type' => 'empty', 'expression' => $expression, 'body' => $body, 'token' => $token];
    }

    /** @return array<string, mixed> */
    private function parseSwitch(string $argument, Token $token): array
    {
        if ($argument === '') {
            throw $this->error('@switch requires an expression (e.g. @switch(status)).', $token);
        }
        $expression = $this->expressions->parse($argument);
        $this->switchDepth++;

        while ($this->peek()->type === 'text' && trim($this->peek()->value) === '') {
            $this->next();
        }

        [$leading, $stop] = $this->parseNodes(['case', 'default', 'endswitch']);
        if ($leading !== []) {
            throw $this->error(
                'Inside @switch, content is only allowed inside @case / @default blocks.',
                $token
            );
        }

        $cases = [];
        $defaultBody = null;

        while ($stop !== null && $stop['name'] === 'case') {
            if ($stop['argument'] === '') {
                throw $this->error('@case requires a value (e.g. @case(\'actif\')).', $stop['token']);
            }
            if ($defaultBody !== null) {
                throw $this->error('@case after @default is not allowed.', $stop['token']);
            }
            $caseToken = $stop['token'];
            $caseExpr = $this->expressions->parse($stop['argument']);
            [$body, $stop] = $this->parseNodes(['case', 'default', 'endswitch']);
            $cases[] = ['expression' => $caseExpr, 'body' => $body, 'token' => $caseToken];
        }

        if ($stop !== null && $stop['name'] === 'default') {
            $defaultToken = $stop['token'];
            [$defaultBody, $stop] = $this->parseNodes(['case', 'default', 'endswitch']);
            if ($stop !== null && $stop['name'] === 'case') {
                throw $this->error('@case after @default is not allowed.', $stop['token']);
            }
            if ($stop !== null && $stop['name'] === 'default') {
                throw $this->error('Multiple @default in @switch are not allowed.', $stop['token']);
            }
            unset($defaultToken);
        }

        $this->switchDepth--;

        if ($stop === null || $stop['name'] !== 'endswitch') {
            throw $this->error('Missing @endswitch for @switch.', $token);
        }
        if ($cases === [] && $defaultBody === null) {
            throw $this->error('@switch must contain at least one @case or @default.', $token);
        }

        return [
            'type' => 'switch',
            'expression' => $expression,
            'cases' => $cases,
            'default' => $defaultBody,
            'token' => $token,
        ];
    }

    /** @return array<string, mixed> */
    private function parseSet(string $argument, Token $token): array
    {
        if (!preg_match('/^\s*([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.+)$/s', $argument, $match)) {
            throw $this->error(
                'Invalid @set syntax. Expected @set(name = expression) (e.g. @set(title = \'Hello\')).',
                $token
            );
        }
        if ($match[1] === 'loop') {
            throw $this->error('Cannot @set the reserved variable "loop".', $token);
        }

        return [
            'type' => 'set',
            'variable' => $match[1],
            'expression' => $this->expressions->parse(trim($match[2])),
            'token' => $token,
        ];
    }

    /** @return array<string, mixed> */
    private function parseScope(string $argument, Token $token): array
    {
        if ($argument === '') {
            throw $this->error(
                '@scope requires an expression (produced by @include … with …).',
                $token
            );
        }
        $expression = $this->expressions->parse($argument);
        [$body, $stop] = $this->parseNodes(['endscope']);
        if ($stop === null || $stop['name'] !== 'endscope') {
            throw $this->error('Missing @endscope for @scope.', $token);
        }

        return [
            'type' => 'scope',
            'expression' => $expression,
            'body' => $body,
            'token' => $token,
        ];
    }

    /** @return array<string, mixed> */
    private function parseBreak(Token $token): array
    {
        if ($this->loopDepth < 1 && $this->switchDepth < 1) {
            throw $this->error(
                '@break is only allowed inside a loop (@for, @foreach, @forelse, @while) or @switch.',
                $token
            );
        }

        return ['type' => 'break', 'token' => $token];
    }

    /** @return array<string, mixed> */
    private function parseContinue(Token $token): array
    {
        if ($this->loopDepth < 1) {
            throw $this->error('@continue is only allowed inside a loop (@for, @foreach, @forelse, @while).', $token);
        }

        return ['type' => 'continue', 'token' => $token];
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
            'Twide: %s (template "%s", line %d, column %d).',
            rtrim($message, '.'),
            $this->templateName,
            $token->line,
            $token->column
        ));
    }
}
