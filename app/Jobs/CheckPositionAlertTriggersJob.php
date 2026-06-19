<?php

namespace App\Jobs;

use App\Alerts\AlertDispatcher;
use App\Enums\AlertEventType;
use App\Models\Position;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class CheckPositionAlertTriggersJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public ?int $positionId = null,
    ) {}

    public function handle(AlertDispatcher $dispatcher): void
    {
        $query = Position::query()->open();

        if ($this->positionId !== null) {
            $query->whereKey($this->positionId);
        }

        foreach ($query->get() as $position) {
            $command = $position->action_command;

            if ($command === 'UPDATE') {
                $dispatcher->queue($position, AlertEventType::SlCanRaise, [
                    'new_sl' => $position->new_sl,
                ]);
            }

            if ($command === 'STOPPED OUT') {
                $dispatcher->queue($position, AlertEventType::StoppedOut);
            }
        }
    }
}
