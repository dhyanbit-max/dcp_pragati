<?php

namespace App\Jobs;

use App\Domain\Notifications\MessageGateways;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** One SMS to a citizen about their grievance. The text holds the reference and status only, never other personal data. */
class SendCitizenSms implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public array $backoff = [60, 300, 900];

    public function __construct(public string $mobile, public string $text)
    {
        $this->onQueue('notifications');
    }

    public function handle(MessageGateways $gateways): void
    {
        $gateways->for('sms')->send($this->mobile, $this->text);
    }
}
