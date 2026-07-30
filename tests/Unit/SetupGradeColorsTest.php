<?php

namespace Tests\Unit;

use App\Support\SetupGradeColors;
use App\Support\SetupGradeDisplay;
use Tests\TestCase;

class SetupGradeColorsTest extends TestCase
{
    public function test_badge_tone_matches_scorecard_hud_modifiers(): void
    {
        $this->assertSame('a-plus', SetupGradeColors::badgeTone('A++'));
        $this->assertSame('a', SetupGradeColors::badgeTone('A'));
        $this->assertSame('b', SetupGradeColors::badgeTone('B'));
        $this->assertSame('c', SetupGradeColors::badgeTone('C'));
        $this->assertSame('no-trade', SetupGradeColors::badgeTone('NO TRADE'));
    }

    public function test_chart_label_colors_are_distinct_per_grade(): void
    {
        $this->assertSame(SetupGradeColors::A_PLUS, SetupGradeColors::chartLabel('A++'));
        $this->assertSame(SetupGradeColors::A, SetupGradeColors::chartLabel('A'));
        $this->assertSame(SetupGradeColors::B, SetupGradeColors::chartLabel('B'));
        $this->assertNotSame(SetupGradeColors::chartLabel('A++'), SetupGradeColors::chartLabel('B'));
    }

    public function test_badge_html_reuses_scorecard_hud_classes(): void
    {
        $html = (string) SetupGradeDisplay::badgeHtml('A++');

        $this->assertStringContainsString('scout-scorecard-hud-grade-badge', $html);
        $this->assertStringContainsString('scout-scorecard-hud-grade-badge--a-plus', $html);
        $this->assertStringContainsString('A++', $html);
    }
}
