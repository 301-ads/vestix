<?php

namespace Tests\Unit;

use App\Models\Asset;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AssetTest extends TestCase
{
    public function test_has_icon_requires_existing_file(): void
    {
        Storage::fake('public');

        $asset = Asset::factory()->make([
            'icon_path' => 'ticker-logos/EQR-icon.svg',
        ]);

        $this->assertFalse($asset->hasIcon());
        $this->assertNull($asset->icon_url);

        Storage::disk('public')->put($asset->icon_path, '<svg></svg>');

        $this->assertTrue($asset->hasIcon());
        $this->assertNotNull($asset->icon_url);
    }
}
