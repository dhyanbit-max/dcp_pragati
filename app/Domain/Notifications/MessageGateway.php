<?php

namespace App\Domain\Notifications;

interface MessageGateway
{
    /** Sends a text message; returns the provider's message id if it gives one. Throws on failure (the job retries). */
    public function send(string $to, string $text): ?string;
}
