<?php

namespace App\Domain\Rules;

final class RuleResultData
{
    public function __construct(
        public readonly string $code,
        public readonly string $outcome, // pass | flag | block
        public readonly ?string $messageKey,
        public readonly array $details,
    ) {}

    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'outcome' => $this->outcome,
            'message' => $this->messageKey ? __($this->messageKey, $this->flatDetails()) : null,
            'details' => $this->details,
        ];
    }

    private function flatDetails(): array
    {
        return array_filter($this->details, fn ($v) => is_scalar($v));
    }
}
