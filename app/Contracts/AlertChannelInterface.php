<?php

namespace App\Contracts;

use App\Models\User;

interface AlertChannelInterface
{
    public function type(): string;

    public function send(User $user, string $message): bool;

    public function isAvailableFor(User $user): bool;
}
