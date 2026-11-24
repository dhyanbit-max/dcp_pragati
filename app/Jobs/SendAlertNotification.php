<?php

namespace App\Jobs;

use App\Domain\Notifications\MessageGateways;
use App\Mail\AlertMail;
use App\Models\AlertNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Throwable;

/** Delivers one alert message (SMS / WhatsApp / email) and records the outcome. Retries 3 times. */
class SendAlertNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [60, 300, 900];

    public function __construct(public int $notificationId)
    {
        $this->onQueue('notifications');
    }

    public function handle(MessageGateways $gateways): void
    {
        $n = AlertNotification::with('alert')->find($this->notificationId);
        if (! $n || $n->status === 'sent') {
            return;
        }

        $providerId = match ($n->channel) {
            'sms', 'whatsapp' => $gateways->for($n->channel)->send($n->to, $n->body),
            'email' => $this->mail($n),
        };

        $n->update(['status' => 'sent', 'sent_at' => now(), 'provider_message_id' => $providerId, 'error' => null]);
    }

    public function failed(Throwable $e): void
    {
        AlertNotification::whereKey($this->notificationId)->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 1000)]);
    }

    private function mail(AlertNotification $n): ?string
    {
        Mail::to($n->to)->send(new AlertMail($n));

        return null;
    }
}
