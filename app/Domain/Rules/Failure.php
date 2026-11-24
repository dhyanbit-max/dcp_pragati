<?php

namespace App\Domain\Rules;

final class Failure
{
    /** @param string $messageKey translation key under lang/{locale}/rules.php */
    public function __construct(
        public readonly string $messageKey,
        public readonly array $details = [],
    ) {}
}
