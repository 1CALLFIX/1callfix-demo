<?php

namespace App\Exceptions;

/** Food F3: the food cart already holds another restaurant's dishes (one restaurant per food cart). */
class FoodCartConflict extends \RuntimeException
{
    public function __construct(public readonly string $inCart, public readonly string $adding)
    {
        parent::__construct("Your cart has dishes from {$inCart}.");
    }
}
