<?php
declare(strict_types=1);

namespace Crell\Historia;

use PHPUnit\Framework\TestCase;

class JustFiddling extends TestCase
{

    public function test_stuff() : void
    {
        $collection = new Collection();

        $image = $collection->load($uuid, 'images');

        $textDoc = $collection->load($uuid, 'markdown');

        $jsonDoc = $collection->load($uuid, 'documents');

        $newDoc = $collection->create('documents');
        $newDoc->whatever;
        $collection->save($newDoc);

        $editDoc = $collection->loadForEditing($uuid, 'markdown');
        $editDoc->whatever();
        $collection->save($editDoc);

        $commit = $collection->newCommit('username');

        $commit
            ->add($newDoc)
            ->add($editDoc)
            ->delete($uuid);

        $txnId = $commit->commit();

        $collection->forTransaction($txnId)->load($uuid, 'images');
    }

    public function test_multiple() : void
    {
        $collection = new Collection();

        $collection->loadMultiple('documents', []);
    }

    public function test_wrapper() : void
    {

        $collection = new Collection();

        $collection->loadMultiple('markdown', $uuids, $language);

    }

    public function test_shelves() : void
    {
        $collection = new Collection();
        $collection->addShelf('documents', new JsonShelf());
        $collection->addShelf('images', new BinaryShelf());
        $collection->setDefaultShelf('documents');

        $collection->forShelf('documents')->forLanguage('fr')->loadMultiple($uuids);

        $collection->forLanguage('fr', function() use ($collection) {
            $collection->loadMultiple($uuids);
        });

    }
}
