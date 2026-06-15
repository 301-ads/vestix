<?php

namespace App\Filament\Auth\Pages;

use Filament\Auth\Pages\Register as BaseRegister;

class Register extends BaseRegister
{
    protected static string $layout = 'filament.components.layout.auth-simple';

    public function hasLogo(): bool
    {
        return false;
    }
}
