<?php

namespace App\Enums;

enum Broker: string
{
    case Revolut = 'revolut';
    case Ibkr = 'ibkr';
    case None = 'none';

    public function label(): string
    {
        return match ($this) {
            self::Revolut => 'Revolut',
            self::Ibkr => 'Interactive Brokers',
            self::None => 'Geen / handmatig',
        };
    }

    public function shortLabel(): string
    {
        return match ($this) {
            self::Revolut => 'Revolut',
            self::Ibkr => 'IBKR',
            self::None => 'Handmatig',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
