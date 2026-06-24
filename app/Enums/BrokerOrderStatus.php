<?php

namespace App\Enums;

enum BrokerOrderStatus: string
{
    case Scout = 'scout';
    case Pending = 'pending';

    public function tableLabel(): string
    {
        return match ($this) {
            self::Scout => 'Scout',
            self::Pending => 'Pending',
        };
    }

    public function badgeColor(): string
    {
        return match ($this) {
            self::Scout => 'gray',
            self::Pending => 'warning',
        };
    }

    public function tableIcon(): ?string
    {
        return match ($this) {
            self::Scout => null,
            self::Pending => 'heroicon-m-clock',
        };
    }
}
