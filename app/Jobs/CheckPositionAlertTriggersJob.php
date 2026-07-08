<?php

namespace App\Jobs;

use App\Alerts\AlertDispatcher;
use App\Enums\AlertEventType;
use App\Models\Position;
use App\Models\PositionAlert;
use App\Support\StopLossProtocol;
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
            if (StopLossProtocol::isRsiOverbought($position)) {
                $dispatcher->queue($position, AlertEventType::Overbought, [
                    'rsi' => $position->scout_rsi,
                ]);
            } else {
                PositionAlert::query()
                    ->where('position_id', $position->id)
                    ->where('event_type', AlertEventType::Overbought)
                    ->delete();
            }

            $command = $position->action_command;

            if ($command === 'UPDATE') {
                $dispatcher->queue($position, AlertEventType::SlCanRaise, [
                    'new_sl' => $position->new_sl,
                ]);
            }

            if ($command === 'STOPPED OUT') {
                $dispatcher->queue($position, AlertEventType::StoppedOut);
            }

            if ($position->isTarget1Hit()) {
                $dispatcher->queue($position, AlertEventType::Target1Hit, [
                    'target_1_price' => $position->target_1_price,
                ]);
            } else {
                PositionAlert::query()
                    ->where('position_id', $position->id)
                    ->where('event_type', AlertEventType::Target1Hit)
                    ->delete();
            }
        }
    }
}
