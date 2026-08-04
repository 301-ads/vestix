<?php

namespace App\Mail;

use App\Models\SquadInvite;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SquadInviteMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public SquadInvite $invite,
        public string $plainToken,
    ) {}

    public function envelope(): Envelope
    {
        $squadName = $this->invite->squad?->name ?? 'een Vestix-squad';

        return new Envelope(
            subject: "Uitnodiging voor {$squadName}",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.squad-invite',
            with: [
                'squadName' => $this->invite->squad?->name ?? 'Squad',
                'inviterName' => $this->invite->inviter?->name ?? 'Een squad-lid',
                'roleLabel' => $this->invite->role?->label() ?? $this->invite->role?->value,
                'acceptUrl' => route('squad-invites.show', ['token' => $this->plainToken]),
                'expiresAt' => $this->invite->expires_at?->timezone(config('app.timezone'))->format('d-m-Y H:i'),
            ],
        );
    }
}
