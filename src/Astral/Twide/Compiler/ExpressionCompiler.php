<?php

declare(strict_types=1);

namespace Astral\Twide\Compiler;

use Astral\Twide\Exceptions\TwideException;

/**
 * Compiles the expression AST into calls to the restricted Twide runtime.
 */
final class ExpressionCompiler
{
    /**
     * @param array<string, mixed> $node
     */
    public function compile(array $node): string
    {
        return match ($node['type'] ?? null) {
            'literal' => var_export($node['value'], true),
            'variable' => '$__twide_runtime->resolve($__twide_context, ' . var_export($node['name'], true) . ')',
            'get' => '$__twide_runtime->get(' . $this->compile($node['target']) . ', ' . $this->compile($node['key']) . ')',
            'call' => '$__twide_runtime->callFunction(' . var_export($node['name'], true) . ', [' . $this->compileList($node['args']) . '])',
            'filter' => '$__twide_runtime->applyFilter(' . var_export($node['name'], true) . ', ' . $this->compile($node['value']) . ', [' . $this->compileList($node['args']) . '])',
            'array' => '[' . $this->compileList($node['items']) . ']',
            'unary' => '(' . $node['operator'] . $this->compile($node['value']) . ')',
            'binary' => $this->compileBinary($node),
            'ternary' => '(' . $this->compile($node['condition']) . ' ? ' . $this->compile($node['yes']) . ' : ' . $this->compile($node['no']) . ')',
            default => throw new TwideException('Unsupported expression node "' . ($node['type'] ?? 'unknown') . '".'),
        };
    }

    /** @param list<array<string, mixed>> $nodes */
    private function compileList(array $nodes): string
    {
        return implode(', ', array_map(fn (array $node): string => $this->compile($node), $nodes));
    }

    /**
     * Compile an expression as a safe isset() check (defined and not null).
     *
     * @param array<string, mixed> $node
     */
    public function compileIsset(array $node): string
    {
        return match ($node['type'] ?? null) {
            'variable' => '$__twide_runtime->issetValue($__twide_context, ' . var_export($node['name'], true) . ')',
            'get' => '$__twide_runtime->issetGet(' . $this->compile($node['target']) . ', ' . $this->compile($node['key']) . ')',
            default => throw new TwideException(
                '@isset only accepts a variable or property/index path (e.g. user, user.name, users[0]).'
            ),
        };
    }

    /** @param array<string, mixed> $node */
    private function compileBinary(array $node): string
    {
        $operator = (string) $node['operator'];
        if (!in_array($operator, ['+', '-', '*', '/', '%', '**', '==', '!=', '===', '!==', '<', '>', '<=', '>=', '&&', '||', '??'], true)) {
            throw new TwideException('Unsupported binary operator "' . $operator . '".');
        }

        return '(' . $this->compile($node['left']) . ' ' . $operator . ' ' . $this->compile($node['right']) . ')';
    }
}
