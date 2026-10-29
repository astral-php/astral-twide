<?php

declare(strict_types=1);

namespace Astral\Twide\Lexer;

final class Token
{
    public function __construct(
        public readonly string $type,
        public readonly string $value,
        public readonly int $offset,
        public readonly int $line,
        public readonly int $column,
    ) {
    }
}
