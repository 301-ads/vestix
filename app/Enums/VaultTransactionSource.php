<?php

namespace App\Enums;

enum VaultTransactionSource: string
{
    case Historical = 'historical';
    case MonthlyConfirm = 'monthly_confirm';

    public function label(): string
    {
        return match ($this) {
            self::Historical => 'Historisch',
            self::MonthlyConfirm => 'Maandbevel',
        };
    }
}
