<?php
declare(strict_types=1);

namespace Crell\Historia;

use Crell\Historia\Shelf\ShelfInterface;
use Crell\Historia\Shelf\TextRecord;
use Crell\Historia\Shelf\TextShelf;
use PHPUnit\Framework\TestCase;


class TestShelf implements ShelfInterface
{
    public function create()
    {

    }
}


class CollectionTest extends TestCase
{

    public function test_can_assign_shelf() : void
    {
        $c = new Collection('col');

        $c->addShelf('documents', new TestShelf());

        $this->assertEquals('documents', $c->getDefaultShelf());
    }

    public function _test_can_initialize() : void
    {

        #$dsn =
        new \PDO($dsn, 'root', 'test');

        $c = new Collection('col');

        $c->addShelf('documents', new TextShelf());

        /** @var TextRecord $a */
        $a = $c->create('documents');

        $a->setValue('Hello World');

        $commit = $c->newCommit();

        $commit->add('documents', $a);

        $c->commit($commit);
    }
}
