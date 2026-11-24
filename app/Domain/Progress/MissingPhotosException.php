<?php

namespace App\Domain\Progress;

use RuntimeException;

/** Raised when an update references photos not yet uploaded. The client retries after uploading (HTTP 409). */
class MissingPhotosException extends RuntimeException
{
    /** @param list<string> $missing */
    public function __construct(public readonly array $missing)
    {
        parent::__construct('Photos not uploaded yet: '.implode(', ', $missing));
    }
}
