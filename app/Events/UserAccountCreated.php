<?php

namespace App\Events;

use App\Enums\UserAccountCreatedSource;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class UserAccountCreated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public User $user,
        public UserAccountCreatedSource $source,
    ) {}
}
