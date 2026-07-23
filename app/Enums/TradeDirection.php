<?php

namespace App\Enums;

enum TradeDirection: string
{
    case Long = 'long';
    case Short = 'short';

    public function label(): string
    {
        return match ($this) {
            self::Long => 'Long',
            self::Short => 'Short',
        };
    }

    public function isShort(): bool
    {
        return $this === self::Short;
    }

    public function isLong(): bool
    {
        return $this === self::Long;
    }
}
