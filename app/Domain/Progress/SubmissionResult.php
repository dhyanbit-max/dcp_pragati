<?php

namespace App\Domain\Progress;

use App\Models\ProgressUpdate;

final class SubmissionResult
{
    public function __construct(
        public readonly ProgressUpdate $update,
        public readonly bool $replayed,
    ) {}

    public function rejected(): bool
    {
        return $this->update->status === ProgressUpdate::STATUS_REJECTED;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->update->id,
            'client_uuid' => $this->update->client_uuid,
            'status' => $this->update->status,
            'flagged' => $this->update->is_flagged,
            'rule_results' => $this->update->ruleResults
                ->where('outcome', '!=', 'pass')
                ->map(fn ($r) => [
                    'code' => $r->rule_code,
                    'outcome' => $r->outcome,
                    'message' => $r->message_key
                        ? __($r->message_key, array_filter($r->details_json ?? [], 'is_scalar'))
                        : null,
                    'details' => $r->details_json,
                ])->values()->all(),
        ];
    }
}
