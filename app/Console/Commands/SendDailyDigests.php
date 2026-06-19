<?php

namespace App\Console\Commands;

use App\Jobs\SendDailyDigestJob;
use Illuminate\Console\Command;

class SendDailyDigests extends Command
{
    protected $signature = 'vestix:send-daily-digests';

    protected $description = 'Verstuur dagelijkse Set & Forget digest naar gebruikers met Telegram.';

    public function handle(): int
    {
        SendDailyDigestJob::dispatch();

        $this->info('Daily digest job dispatched.');

        return self::SUCCESS;
    }
}
