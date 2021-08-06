<?php
declare(strict_types=1);

namespace Crell\Historia;

use Throwable;

class RecordNotFound extends \InvalidArgumentException
{

    public static function forUuid(string $uuid, string $language): static
    {
        $message = sprintf('No record found: %s in language %s', $uuid, $language);
        return new static($message);
    }
}
