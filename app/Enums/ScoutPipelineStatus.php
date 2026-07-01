<?php

namespace App\Enums;

use App\Models\Position;

enum ScoutPipelineStatus: string
{
    case Scout = 'scout';
    case Pending = 'pending';
    case Active = 'active';

    public static function fromPosition(Position $position): self
    {
        if ($position->broker_order_status === BrokerOrderStatus::Pending) {
            return self::Active;
        }

        if ($position->market_open_reminder_on !== null) {
            return self::Pending;
        }

        return self::Scout;
    }

    public function label(): string
    {
        return match ($this) {
            self::Scout => 'Scout',
            self::Pending => 'Pending',
            self::Active => 'Active',
        };
    }

    public function badgeColor(): string
    {
        return match ($this) {
            self::Scout => 'gray',
            self::Pending => 'info',
            self::Active => 'warning',
        };
    }

    public function tableIcon(): ?string
    {
        return match ($this) {
            self::Scout => null,
            self::Pending => 'heroicon-m-bell-alert',
            self::Active => 'heroicon-m-clock',
        };
    }

    public function tooltip(Position $position): ?string
    {
        return match ($this) {
            self::Scout => 'Op de radar — nog geen order gepland.',
            self::Pending => $position->market_open_reminder_on !== null
                ? 'Wacht op market open — reminder op '.$position->market_open_reminder_on->format('d-m-Y').'.'
                : 'Wacht op market open.',
            self::Active => 'Buy-stop staat live bij je broker.',
        };
    }
}
