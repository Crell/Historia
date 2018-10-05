<?php
declare(strict_types=1);

namespace Crell\Historia\Shelf;

class TextRecord
{
    /** @var string */
    protected $uuid;

    protected $value = '';

    public function __construct(string $uuid, $value = '')
    {
        $this->uuid = $uuid;
        $this->value = $value;
    }

    public function uuid() : string
    {
        return $this->uuid;
    }

    public function value() : string
    {
        return $this->value;
    }

    public function setValue(string $value) : self
    {
        $this->value = $value;
        return $this;
    }
}
