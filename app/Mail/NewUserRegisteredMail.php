<?php

namespace App\Mail;

use App\Enums\UserAccountCreatedSource;
use App\Filament\Resources\Admin\UserResource;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class NewUserRegisteredMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public UserAccountCreatedSource $source,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Nieuw Vestix-account: '.$this->user->email,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.new-user-registered',
            with: [
                'userName' => $this->user->name,
                'userEmail' => $this->user->email,
                'sourceLabel' => $this->source->label(),
                'createdAt' => $this->user->created_at?->timezone(config('app.timezone'))->format('d-m-Y H:i'),
                'adminUrl' => UserResource::getUrl('index'),
            ],
        );
    }
}
