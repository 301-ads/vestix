<?php

namespace App\Enums;

use App\Models\Position;

enum ScoutPipelineStatus: string
{
    case Scout = 'scout';
    case Pending = 'pending';
    case Active = 'active';
    case ReviewRequired = 'review_required';

    public static function fromPosition(Position $position): self
    {
        if ($position->buy_stop_review_required_on !== null) {
            return self::ReviewRequired;
        }

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
            self::Scout => 'Pending',
            self::Pending => 'Order Plan',
            self::Active => 'Active',
            self::ReviewRequired => 'Review',
        };
    }

    public function badgeColor(): string
    {
        return match ($this) {
            self::Scout => 'gray',
            self::Pending => 'info',
            self::Active => 'info',
            self::ReviewRequired => 'warning',
        };
    }

    public function tableIcon(): ?string
    {
        return match ($this) {
            self::Scout => null,
            self::Pending => 'heroicon-m-shopping-cart',
            self::Active => 'heroicon-m-clock',
            self::ReviewRequired => 'heroicon-m-exclamation-triangle',
        };
    }

    public function tooltip(Position $position): ?string
    {
        return match ($this) {
            self::Scout => 'Pending — nog geen order bij je broker.',
            self::Pending => $position->market_open_reminder_on !== null
                ? 'In Order Plan (winkelwagen + Telegram digest) voor '.config('vestix.execution_digest.time', '15:31').' op '.$position->market_open_reminder_on->format('d-m-Y').'.'
                : 'In Order Plan.',
            self::Active => 'Active — order staat live bij je broker.',
            self::ReviewRequired => $position->buy_stop_review_required_on !== null
                ? 'Buy-stop review vereist sinds '.$position->buy_stop_review_required_on->format('d-m-Y').'.'
                : 'Buy-stop review vereist.',
        };
    }
}
