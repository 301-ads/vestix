<?php

namespace App\Enums;

enum EarningsExitUrgency: string
{
    case Prepare = 'prepare';
    case ExitToday = 'exit_today';
    case Overdue = 'overdue';
}
