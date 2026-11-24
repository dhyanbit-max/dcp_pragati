<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Collection;

/** Daily 08:00 digest for DPCs and DCs (docs/08 §3): new alerts, escalations, approvals waiting. */
class AlertDigest extends Mailable
{
    public function __construct(
        public User $user,
        public Collection $newAlerts,
        public Collection $escalated,
        public int $pendingApprovals,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'DCP Pragati: '.__('Daily alert summary', [], $this->user->preferred_locale).' '.now()->format('d M Y'));
    }

    public function content(): Content
    {
        return new Content(text: 'mail.digest', with: ['url' => route('alerts')]);
    }
}
