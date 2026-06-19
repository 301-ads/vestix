<?php

namespace Tests\Unit;

use App\Support\TickerIconPalette;
use Tests\TestCase;

class TickerIconPaletteTest extends TestCase
{
    public function test_extracts_background_fill_from_svg_rect(): void
    {
        $svg = <<<'SVG'
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 36 36">
            <rect width="36" height="36" fill="#17255f"/>
            <path d="M10 10h16v16H10z" fill="#ffffff"/>
        </svg>
        SVG;

        $this->assertSame('#17255f', TickerIconPalette::extractFromContents($svg, 'svg'));
    }
}
