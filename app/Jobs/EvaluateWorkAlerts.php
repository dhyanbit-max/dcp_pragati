<?php

namespace App\Jobs;

use App\Domain\Alerts\AlertEvaluator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Re-checks alerts for one work after an event (update approved, release recorded) so alerts open/close promptly. */
class EvaluateWorkAlerts implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $uniqueFor = 60;

    public function __construct(public int $workId) {}

    public function uniqueId(): string
    {
        return (string) $this->workId;
    }

    public function handle(AlertEvaluator $evaluator): void
    {
        $evaluator->evaluate([$this->workId]);
    }
}
