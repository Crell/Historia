<?php
declare(strict_types=1);

namespace Crell\Historia\Shelf;

use Ramsey\Uuid\Uuid;

class TextShelf implements ShelfInterface
{

    public function create()
    {
        $uuid = Uuid::uuid4()->toString();
        $r = new TextRecord($uuid);

        return $r;
    }
}
