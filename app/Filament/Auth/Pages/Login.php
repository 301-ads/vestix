<?php

namespace App\Filament\Auth\Pages;

use Filament\Auth\Pages\Login as BaseLogin;

class Login extends BaseLogin
{
    protected static string $layout = 'filament.components.layout.auth-simple';

    public function hasLogo(): bool
    {
        return false;
    }
}
