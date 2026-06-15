<?php

namespace App\Events;

use App\Models\Position;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SquadRadarTargetPosted
{
    use Dispatchable, SerializesModels;

    public function __construct(public Position $position) {}
}
