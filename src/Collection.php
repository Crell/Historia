<?php
declare(strict_types=1);

namespace Crell\Historia;


class Collection
{

    /** @var ShelfInterface */
    protected $shelves;

    /** @var string */
    protected $defaultShelf;

    /**
     * @var string
     */
    protected $language;


    public function __construct(string $name, $language = 'en')
    {
        $this->name = $name;
        $this->language = $language;
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



}
