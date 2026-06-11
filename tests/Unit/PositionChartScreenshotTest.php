<?php

namespace Tests\Unit;

use App\Models\Position;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PositionChartScreenshotTest extends TestCase
{
    use RefreshDatabase;

    public function test_deleting_position_removes_chart_screenshot_files(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('position-charts/entry.jpg', 'entry');
        Storage::disk('public')->put('position-charts/exit.jpg', 'exit');

        $position = Position::factory()->create([
            'entry_chart_screenshot_path' => 'position-charts/entry.jpg',
            'exit_chart_screenshot_path' => 'position-charts/exit.jpg',
        ]);

        $position->delete();

        Storage::disk('public')->assertMissing('position-charts/entry.jpg');
        Storage::disk('public')->assertMissing('position-charts/exit.jpg');
    }

    public function test_replacing_entry_screenshot_deletes_old_file(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('position-charts/old-entry.jpg', 'old');
        Storage::disk('public')->put('position-charts/new-entry.jpg', 'new');

        $position = Position::factory()->create([
            'entry_chart_screenshot_path' => 'position-charts/old-entry.jpg',
        ]);

        $position->update([
            'entry_chart_screenshot_path' => 'position-charts/new-entry.jpg',
        ]);

        Storage::disk('public')->assertMissing('position-charts/old-entry.jpg');
        Storage::disk('public')->assertExists('position-charts/new-entry.jpg');
    }

    public function test_archive_with_exit_price_stores_exit_chart_path(): void
    {
        $position = Position::factory()->create(['status' => 'open']);

        $position->archiveWithExitPrice(95.50, 'position-charts/exit-on-archive.jpg');

        $position->refresh();

        $this->assertEquals('closed', $position->status);
        $this->assertEquals(95.50, (float) $position->exit_price);
        $this->assertEquals('position-charts/exit-on-archive.jpg', $position->exit_chart_screenshot_path);
    }
}
