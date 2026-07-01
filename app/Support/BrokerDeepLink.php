<?php

namespace App\Support;

use App\Enums\Broker;

class BrokerDeepLink
{
    public static function forStock(?Broker $broker, string $ticker): ?string
    {
        if ($broker === null || $broker === Broker::None) {
            return null;
        }

        $template = config("vestix.brokers.{$broker->value}.stock_url");

        if (! is_string($template) || $template === '') {
            return null;
        }

        return str_replace('{ticker}', strtolower($ticker), $template);
    }

    public static function linkLabel(?Broker $broker): ?string
    {
        return match ($broker) {
            Broker::Revolut => 'Open in Revolut',
            default => null,
        };
    }
}
