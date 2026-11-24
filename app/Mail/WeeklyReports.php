<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class WeeklyReports extends Mailable
{
    /** @param array<string, string> $files  download name => path */
    public function __construct(public User $user, public array $files) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'DCP Pragati: '.__('Weekly reports').' '.now()->format('d M Y'));
    }

    public function content(): Content
    {
        return new Content(text: 'mail.weekly-reports', with: ['url' => route('reports')]);
    }

    public function attachments(): array
    {
        return collect($this->files)->map(fn ($path, $name) => Attachment::fromData(fn () => file_get_contents($path), $name)
            ->withMime('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'))->values()->all();
    }
}
