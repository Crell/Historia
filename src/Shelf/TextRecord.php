<?php
declare(strict_types=1);

namespace Crell\Historia\Shelf;

class TextRecord
{

    public function __construct(
        protected string $uuid,
        protected mixed $value = '',
    ) {}

    public function uuid(): string
    {
        return $this->uuid;
    }

    public function value(): string
    {
        return $this->value;
    }

    public function setValue(string $value): static
    {
        $this->value = $value;
        return $this;
    }
}
