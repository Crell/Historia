<?php
declare(strict_types=1);

namespace Crell\Historia;


use Crell\Historia\Shelf\ShelfInterface;

class Collection
{

    /** @var ShelfInterface[] */
    protected $shelves;

    /** @var string */
    protected $defaultShelf;

    /**
     * @var string
     */
    protected $language = 'en';

    /**
     * @var string
     */
    protected $workspace = 'default';

    public function __construct(string $name, $language = 'en')
    {
        $this->name = $name;
        $this->language = $language;
    }

    public function initializeSchema() : self
    {

        foreach ($this->shelves as $name => $shelf) {
            
        }

        return $this;
    }

    public function addShelf(string $name, ShelfInterface $shelf) : self
    {
        $this->shelves[$name] = $shelf;

        if (empty($this->defaultShelf)) {
            $this->setDefaultShelf($name);
        }

        return $this;
    }

    public function setDefaultShelf(string $name) : self
    {
        $this->defaultShelf = $name;
        return $this;
    }

    public function getDefaultShelf() : string
    {
        return $this->defaultShelf;
    }

    public function name() : string
    {
        return $this->name;
    }

    public function forLanguage(string $language) : self
    {
        $new = clone $this;
        $new->language = $language;

        return $new;
    }

    public function forWorkspace(string $workspace) : self
    {
        $new = clone $this;
        $new->workspace = $workspace;

        return $new;
    }

    public function create(string $shelf = null)
    {
        $shelf = $shelf ?? $this->defaultShelf;
        return $this->shelves[$shelf]->create();
    }

    public function newCommit(string $author = null, string $message = null) : Commit
    {
        return new Commit($author, $message);
    }

    public function commit(Commit $commit)
    {
        $records = $commit->getRecords();
        // @todo Hard code text for now. We'll sort this out later.

        $records = $records[$this->defaultShelf];

        // Start DB transaction



        // Close DB transaction
    }
}
