<?php

namespace App\Domain\Production;

use RuntimeException;

final class InsufficientIngredients extends RuntimeException
{
    public function __construct(public readonly array $ingredients)
    {
        parent::__construct('Production ingredients are insufficient.');
    }
}
