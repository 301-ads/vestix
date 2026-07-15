<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\Prestaties;
use App\Filament\Widgets\AlphaTrackerChart;
use App\Filament\Widgets\AlphaTrackerStatsWidget;
use App\Filament\Widgets\PerformanceComingSoonWidget;
use App\Models\BankrollSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PrestatiesTest extends TestCase
{
    use RefreshDatabase;

    public function test_prestaties_page_renders(): void
    {
        ['user' => $user, 'squad' => $squad] = $this->createUserWithSquad();

        $this->actingAsFilamentUser($user, $squad);

        Livewire::test(Prestaties::class)
            ->assertOk()
            ->assertSee('Meer performance-data');
    }

    public function test_prestaties_widget_order(): void
    {
        $widgets = (new Prestaties)->getWidgets();

        $this->assertSame([
            AlphaTrackerStatsWidget::class,
            AlphaTrackerChart::class,
            PerformanceComingSoonWidget::class,
        ], $widgets);
    }

    public function test_prestaties_shows_alpha_tracker_when_two_snapshots_exist(): void
    {
        ['user' => $user, 'squad' => $squad] = $this->createUserWithSquad();

        BankrollSnapshot::query()->create([
            'user_id' => $user->id,
            'amount' => 10000,
            'benchmark_ticker' => 'SPY',
            'benchmark_close' => 500,
            'recorded_on' => '2026-01-04',
            'recorded_at' => now(),
        ]);

        BankrollSnapshot::query()->create([
            'user_id' => $user->id,
            'amount' => 10635,
            'benchmark_ticker' => 'SPY',
            'benchmark_close' => 520,
            'recorded_on' => '2026-01-11',
            'recorded_at' => now(),
        ]);

        $this->actingAsFilamentUser($user, $squad);

        Livewire::test(Prestaties::class)
            ->assertSee('Alpha Tracker')
            ->assertSee('Jouw Rendement (YTD)')
            ->assertSee('Jouw Alpha')
            ->assertSee('Meer performance-data');
    }
}
