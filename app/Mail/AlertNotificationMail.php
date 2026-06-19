<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AlertNotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $alertMessage,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Vestix Alert',
        );
    }

    public function content(): Content
    {
        return new Content(
            text: 'mail.alert-notification',
        );
    }
}
