<?php

namespace App\Console\Commands;

use Database\Seeders\FreeFirstDemoSeeder;
use Illuminate\Console\Command;

class SeedFreeFirstDemo extends Command
{
    protected $signature = 'vestix:seed-free-first-demo
                            {--email= : User e-mail (default: VESTIX_DEMO_EMAIL of davy@301.digital)}
                            {--fresh : Wis bestaande posities/snapshots van deze user eerst}';

    protected $description = 'Seed local fake data so Free-First roadmap UI is fully visible';

    public function handle(FreeFirstDemoSeeder $seeder): int
    {
        if (app()->environment('production')) {
            $this->error('Geblokkeerd in production.');

            return self::FAILURE;
        }

        $seeder->run(
            email: $this->option('email') ?: null,
            fresh: (bool) $this->option('fresh'),
        );

        return self::SUCCESS;
    }
}
