<?php
declare(strict_types=1);

namespace Crell\Historia;

use PHPUnit\Framework\TestCase;


class TestShelf implements ShelfInterface {}


class CollectionTest extends TestCase
{

    public function test_can_assign_shelf() : void
    {
        $c = new Collection();

        $c->addShelf('documents', new TestShelf());

        $this->assertEquals('documents', $c->getDefaultShelf());
    }
}