<?php

namespace App\Enums;

enum ScoutReviewStatus: string
{
    case PendingVisualReview = 'pending_visual_review';
    case ActiveScout = 'active_scout';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::PendingVisualReview => 'Visuele review',
            self::ActiveScout => 'Actieve scout',
            self::Rejected => 'Afgewezen',
        };
    }
}
