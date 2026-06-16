<?php

namespace App\Listeners;

use App\Events\UserAccountCreated;
use App\Mail\NewUserRegisteredMail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Mail;

class SendNewUserAdminNotification implements ShouldQueue
{
    public function handle(UserAccountCreated $event): void
    {
        $recipients = config('vestix.admin_notification_emails', []);

        if ($recipients === []) {
            return;
        }

        $mailable = new NewUserRegisteredMail($event->user, $event->source);

        foreach ($recipients as $email) {
            Mail::to($email)->send($mailable);
        }
    }
}
