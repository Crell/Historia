<?php
declare(strict_types=1);

namespace Crell\Historia;

class Record
{
    /**
     * @var string
     */
    public $uuid = '';

    /**
     * @var string
     */
    public $document = '';

    /**
     * @var \DateTimeImmutable
     */
    public $updated;

    public function __construct()
    {
        $this->updated = new \DateTimeImmutable();
    }
}
