<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_homepage_shows_welcome_page(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Vestix');
    }
}
