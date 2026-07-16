<?php

namespace Tests\Unit;

use App\Support\StopLimitBuffer;
use Tests\TestCase;

class StopLimitBufferTest extends TestCase
{
    public function test_buffer_tiers_at_boundaries(): void
    {
        $this->assertSame(0.05, StopLimitBuffer::bufferFor(19.99));
        $this->assertSame(0.10, StopLimitBuffer::bufferFor(20.00));
        $this->assertSame(0.10, StopLimitBuffer::bufferFor(49.99));
        $this->assertSame(0.15, StopLimitBuffer::bufferFor(50.00));
        $this->assertSame(0.15, StopLimitBuffer::bufferFor(99.99));
        $this->assertSame(0.25, StopLimitBuffer::bufferFor(100.00));
        $this->assertSame(0.25, StopLimitBuffer::bufferFor(250.00));
    }

    public function test_limit_price_adds_buffer(): void
    {
        $this->assertSame(9.66, StopLimitBuffer::limitPrice(9.61));
        $this->assertSame(40.51, StopLimitBuffer::limitPrice(40.41));
        $this->assertSame(56.60, StopLimitBuffer::limitPrice(56.45));
        $this->assertSame(71.95, StopLimitBuffer::limitPrice(71.80));
        $this->assertSame(120.25, StopLimitBuffer::limitPrice(120.00));
    }

    public function test_non_positive_stop_returns_zero(): void
    {
        $this->assertSame(0.0, StopLimitBuffer::bufferFor(0));
        $this->assertSame(0.0, StopLimitBuffer::limitPrice(-1));
    }
}
