<?php
declare(strict_types=1);

namespace Crell\Historia;

use Throwable;

class RecordNotFound extends \InvalidArgumentException
{

    public static function forUuid(string $uuid) : self
    {
        $message = sprintf('No record found: %s', $uuid);
        return new static($message);
    }
}