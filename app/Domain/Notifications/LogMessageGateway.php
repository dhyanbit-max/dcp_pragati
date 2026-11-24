<?php

namespace App\Domain\Notifications;

use Illuminate\Support\Facades\Log;
use RuntimeException;

/** Development only: writes the message to the log instead of sending it. */
class LogMessageGateway implements MessageGateway
{
    public function __construct(private readonly string $channel) {}

    public function send(string $to, string $text): ?string
    {
        if (app()->isProduction()) {
            throw new RuntimeException("The log {$this->channel} gateway must not be used in production. Configure a real provider.");
        }
        Log::info("DCP Pragati {$this->channel} to {$to}: {$text}");

        return 'log-'.bin2hex(random_bytes(6));
    }
}
