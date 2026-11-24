<?php

namespace App\Mail;

use App\Models\AlertNotification;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class AlertMail extends Mailable
{
    public function __construct(public AlertNotification $notification) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'DCP Pragati: '.__('alert_types.'.$this->notification->alert->alert_code.'.title'));
    }

    public function content(): Content
    {
        return new Content(text: 'mail.alert', with: [
            'body' => $this->notification->body,
            'url' => route('alerts'),
        ]);
    }
}
