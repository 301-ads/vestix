<?php

namespace App\Filament\Auth\Pages;

use Filament\Auth\Pages\PasswordReset\RequestPasswordReset as BaseRequestPasswordReset;

class RequestPasswordReset extends BaseRequestPasswordReset
{
    protected static string $layout = 'filament.components.layout.auth-simple';

    public function hasLogo(): bool
    {
        return false;
    }
}
