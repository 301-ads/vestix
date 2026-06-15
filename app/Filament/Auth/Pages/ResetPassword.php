<?php

namespace App\Filament\Auth\Pages;

use Filament\Auth\Pages\PasswordReset\ResetPassword as BaseResetPassword;

class ResetPassword extends BaseResetPassword
{
    protected static string $layout = 'filament.components.layout.auth-simple';

    public function hasLogo(): bool
    {
        return false;
    }
}
