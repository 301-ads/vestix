<?php

namespace Tests\Feature\Console;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class VestixSmokeTestTest extends TestCase
{
    use RefreshDatabase;

    public function test_smoke_test_passes_in_testing_environment(): void
    {
        config([
            'app.env' => 'testing',
            'app.debug' => false,
            'app.url' => 'http://localhost',
            'vestix.alpha_vantage.api_key' => 'test',
            'vestix.polygon.api_key' => 'test',
            'vestix.telegram.bot_token' => 'test',
        ]);

        Http::fake(['http://localhost/up' => Http::response('OK', 200)]);

        if (! File::exists(public_path('storage'))) {
            File::ensureDirectoryExists(public_path('storage'));
        }

        $this->artisan('vestix:smoke-test')
            ->assertSuccessful();
    }

    public function test_smoke_test_fails_when_database_unreachable(): void
    {
        Http::fake();

        \Illuminate\Support\Facades\DB::shouldReceive('connection')
            ->once()
            ->andThrow(new \RuntimeException('Connection refused'));

        $this->artisan('vestix:smoke-test')
            ->assertFailed();
    }
}
