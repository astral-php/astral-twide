<?php

declare(strict_types=1);

namespace Astral\Twide\Runtime;

use Astral\Twide\Registry\FilterRegistry;
use Astral\Twide\Registry\FunctionRegistry;
use Astral\Twide\Exceptions\TwideException;
use Stringable;
use Traversable;

final class TwideRuntime
{
    public function __construct(
        private readonly FunctionRegistry $functions,
        private readonly FilterRegistry $filters,
    ) {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function resolve(array $context, string $name): mixed
    {
        return $context[$name] ?? null;
    }

    public function get(mixed $target, mixed $key): mixed
    {
        if (is_array($target)) {
            return is_int($key) || is_string($key) ? ($target[$key] ?? null) : null;
        }

        if (is_object($target) && is_string($key)) {
            // Public properties only; no magic getters or arbitrary method calls.
            $properties = get_object_vars($target);
            return $properties[$key] ?? null;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function issetValue(array $context, string $name): bool
    {
        return array_key_exists($name, $context) && $context[$name] !== null;
    }

    public function issetGet(mixed $target, mixed $key): bool
    {
        if (is_array($target)) {
            return (is_int($key) || is_string($key))
                && array_key_exists($key, $target)
                && $target[$key] !== null;
        }

        if (is_object($target) && is_string($key)) {
            $properties = get_object_vars($target);

            return array_key_exists($key, $properties) && $properties[$key] !== null;
        }

        return false;
    }

    public function isEmpty(mixed $value): bool
    {
        return empty($value);
    }

    /** @param list<mixed> $arguments */
    public function callFunction(string $name, array $arguments): mixed
    {
        return ($this->functions->get($name))(...$arguments);
    }

    /** @param list<mixed> $arguments */
    public function applyFilter(string $name, mixed $value, array $arguments): mixed
    {
        return ($this->filters->get($name))($value, ...$arguments);
    }

    public function escape(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            $value = $value ? '1' : '';
        } elseif (is_array($value) || is_resource($value)) {
            throw new TwideException('Cannot render an array or resource as an escaped scalar.');
        } elseif (is_object($value) && !$value instanceof Stringable) {
            throw new TwideException('Cannot render a non-stringable object in an echo expression.');
        }

        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public function raw(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (!is_string($value) && !$value instanceof Stringable) {
            throw new TwideException('Raw output accepts only strings or Stringable values.');
        }
        return (string) $value;
    }

    /**
     * Normalize iterables to arrays so the compiler can expose a reliable
     * loop.length and deterministic first/last metadata.
     *
     * @return array<array-key, mixed>
     */
    public function iterable(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if ($value instanceof Traversable) {
            return iterator_to_array($value);
        }
        if ($value === null) {
            return [];
        }
        throw new TwideException('A @for expression must evaluate to an array or Traversable.');
    }
}
