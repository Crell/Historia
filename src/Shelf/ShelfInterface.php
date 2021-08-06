<?php
declare(strict_types=1);

namespace Crell\Historia\Shelf;

/**
 * I think this was for storing different body types: text, json, or blob.
 *
 * This may not be the best design.  TBD.
 */
interface ShelfInterface
{
    public function create();
}
