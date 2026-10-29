<?php

declare(strict_types=1);

namespace Astral\Twide\Registry;

final class FilterRegistry extends CallableRegistry
{
    public function __construct()
    {
        parent::__construct('filter');
    }
}
