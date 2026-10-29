<?php

declare(strict_types=1);

namespace Astral\Twide\Registry;

use Astral\Twide\Exceptions\ExtensionRegistrationException;
use InvalidArgumentException;

class CallableRegistry
{
    /** @var array<string, callable> */
    private array $callables = [];

    public function __construct(private readonly string $kind)
    {
    }

    public function register(string $name, callable $callable, bool $replace = false): void
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name)) {
            throw new InvalidArgumentException(sprintf('Invalid %s name "%s".', $this->kind, $name));
        }

        if (isset($this->callables[$name]) && !$replace) {
            throw new ExtensionRegistrationException(sprintf(
                'A %s named "%s" is already registered.',
                $this->kind,
                $name
            ));
        }

        $this->callables[$name] = $callable;
    }

    public function has(string $name): bool
    {
        return isset($this->callables[$name]);
    }

    public function get(string $name): callable
    {
        if (!$this->has($name)) {
            throw new ExtensionRegistrationException(sprintf(
                'Unknown %s "%s".',
                $this->kind,
                $name
            ));
        }

        return $this->callables[$name];
    }

    /**
     * @return array<string, callable>
     */
    public function all(): array
    {
        return $this->callables;
    }
}
