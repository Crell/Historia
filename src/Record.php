<?php
declare(strict_types=1);

namespace Crell\Historia;

class Record
{
    public string $uuid = '';

    public string $document = '';

    // @todo This properly likely means we need to avoid the PDO-load-to-object and do it ourselves.
    public \DateTimeImmutable|string $updated;

    public string $language = '';

    public function __construct(string $uuid = '', string $language = 'en', string $document = '')
    {
        // The empty checks here are because this class is instantiated from the database via PDO, which populates
        // the properties before the constructor is called. So the constructor gets called second, then overwrites
        // the values with the defaults above. Because PDO is stupid.
        $this->uuid = $this->uuid ?: $uuid;
        $this->language = $this->language ?: $language;
        $this->document = $this->document ?: $document;

        // The date time value in the database is a string, but we want it upcast to an object. Do that here.
        if (is_string($this->updated)) {
            $this->updated = new \DateTimeImmutable($this->updated);
        } else {
            $this->updated = $this->updated ?: new \DateTimeImmutable();
        }
    }
}
