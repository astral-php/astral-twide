<?php

declare(strict_types=1);

namespace Astral\Twide\Registry;

final class FunctionRegistry extends CallableRegistry
{
    public function __construct()
    {
        parent::__construct('function');
    }
}
