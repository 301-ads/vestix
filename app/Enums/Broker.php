<?php

namespace App\Enums;

enum Broker: string
{
    case Revolut = 'revolut';
    case None = 'none';

    public function label(): string
    {
        return match ($this) {
            self::Revolut => 'Revolut',
            self::None => 'Geen / handmatig',
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
