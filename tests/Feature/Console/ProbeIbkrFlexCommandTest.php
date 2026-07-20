<?php

namespace Tests\Feature\Console;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProbeIbkrFlexCommandTest extends TestCase
{
    public function test_probe_prints_effective_config_without_calling_ibkr(): void
    {
        config([
            'vestix.ibkr.flex.base_url' => 'https://ndcdyn.interactivebrokers.com/AccountManagement/FlexWebService',
            'vestix.ibkr.flex.token' => '123456789012',
            'vestix.ibkr.flex.query_id' => '1575288',
            'vestix.ibkr.reader' => 'flex',
        ]);

        $this->artisan('vestix:probe-ibkr-flex')
            ->expectsOutputToContain('ndcdyn.interactivebrokers.com/AccountManagement/FlexWebService')
            ->expectsOutputToContain('SendRequest')
            ->expectsOutputToContain('1575288')
            ->expectsOutputToContain('9012')
            ->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_probe_warns_on_legacy_base_url(): void
    {
        config([
            'vestix.ibkr.flex.base_url' => 'https://gdcdyn.interactivebrokers.com/Universal/servlet',
            'vestix.ibkr.flex.token' => 'token',
            'vestix.ibkr.flex.query_id' => '1',
        ]);

        $this->artisan('vestix:probe-ibkr-flex')
            ->expectsOutputToContain('Legacy Flex URL detected')
            ->assertSuccessful();
    }
}
