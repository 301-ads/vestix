<?php

namespace App\Jobs;

use App\Alerts\AlertDispatcher;
use App\Enums\AlertEventType;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendAlertJob implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public int $userId,
        public int $positionId,
        public AlertEventType $event,
        public array $context = [],
    ) {}

    public function handle(AlertDispatcher $dispatcher): void
    {
        $dispatcher->dispatchNow(
            $this->userId,
            $this->positionId,
            $this->event,
            $this->context,
        );
    }
}
