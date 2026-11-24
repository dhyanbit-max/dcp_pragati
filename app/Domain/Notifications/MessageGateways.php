<?php

namespace App\Domain\Notifications;

use InvalidArgumentException;

/** Resolves the SMS / WhatsApp gateway from config('dcp.notifications'). Real providers plug in here (docs/18 Q-7). */
class MessageGateways
{
    public function for(string $channel): MessageGateway
    {
        $driver = config("dcp.notifications.$channel");

        return match ($driver) {
            'log' => new LogMessageGateway($channel),
            default => throw new InvalidArgumentException("No $channel gateway configured (driver '$driver')."),
        };
    }
}
