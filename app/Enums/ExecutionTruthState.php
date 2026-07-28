<?php

namespace App\Enums;

enum ExecutionTruthState: string
{
    case Planned = 'planned';
    case SubmittedAtBroker = 'submitted_at_broker';
    case SyncedOpen = 'synced_open';
    case SyncedPartial = 'synced_partial';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Planned => 'Gepland',
            self::SubmittedAtBroker => 'Geplaatst bij broker',
            self::SyncedOpen => 'Gesynchroniseerd (open)',
            self::SyncedPartial => 'Gesynchroniseerd (deels)',
            self::Closed => 'Gesloten',
        };
    }

    public function badgeColor(): string
    {
        return match ($this) {
            self::Planned => 'gray',
            self::SubmittedAtBroker => 'warning',
            self::SyncedOpen => 'success',
            self::SyncedPartial => 'info',
            self::Closed => 'gray',
        };
    }

    public function sourceLabel(): string
    {
        return match ($this) {
            self::Planned => 'planned',
            self::SubmittedAtBroker => 'handmatig',
            self::SyncedOpen, self::SyncedPartial => 'broker-synced',
            self::Closed => 'handmatig',
        };
    }
}
