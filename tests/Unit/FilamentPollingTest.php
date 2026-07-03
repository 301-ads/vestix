<?php

namespace Tests\Unit;

use App\Support\FilamentPolling;
use Tests\TestCase;

class FilamentPollingTest extends TestCase
{
    public function test_interval_is_ten_seconds(): void
    {
        $this->assertSame('10s', FilamentPolling::INTERVAL);
    }
}
