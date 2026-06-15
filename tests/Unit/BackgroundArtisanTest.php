<?php

namespace Tests\Unit;

use App\Support\BackgroundArtisan;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class BackgroundArtisanTest extends TestCase
{
    public function test_dispatch_uses_configured_php_binary(): void
    {
        Process::fake();

        config(['app.php_binary' => PHP_BINARY]);

        BackgroundArtisan::dispatch('vestix:fetch-data', ['user-id' => 1]);

        Process::assertRan(fn ($process): bool => $process->command[0] === PHP_BINARY);
    }
}
