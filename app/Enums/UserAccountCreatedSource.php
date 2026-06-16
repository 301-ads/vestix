<?php

namespace App\Enums;

enum UserAccountCreatedSource: string
{
    case Registration = 'registration';
    case SquadInvite = 'squad_invite';
    case Artisan = 'artisan';

    public function label(): string
    {
        return match ($this) {
            self::Registration => 'Publieke registratie',
            self::SquadInvite => 'Squad-uitnodiging',
            self::Artisan => 'Artisan commando',
        };
    }
}
