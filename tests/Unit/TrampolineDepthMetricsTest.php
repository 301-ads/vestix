<?php

namespace Tests\Unit;

use App\Services\FinnhubService;
use App\Support\PreBounceExtensionCalculator;
use App\Support\RelativeVolumeCalculator;
use App\Support\SectorTrendResolver;
use Tests\TestCase;

class TrampolineDepthMetricsTest extends TestCase
{
    public function test_rvol_above_threshold_on_bounce_day(): void
    {
        $bars = $this->barsPayload(todayVolume: 1_500_000, priorVolumes: array_fill(0, 20, 1_000_000));

        $result = RelativeVolumeCalculator::resolve($bars, 100.0, null);

        $this->assertEquals(1.50, $result['relative_volume']);
        $this->assertTrue($result['bounce_volume_above_average']);
        $this->assertEquals(1_500_000, $result['bounce_day_volume']);
    }

    public function test_rvol_below_threshold_on_bounce_day(): void
    {
        $bars = $this->barsPayload(todayVolume: 900_000, priorVolumes: array_fill(0, 20, 1_000_000));

        $result = RelativeVolumeCalculator::resolve($bars, 100.0, null);

        $this->assertEquals(0.90, $result['relative_volume']);
        $this->assertFalse($result['bounce_volume_above_average']);
    }

    public function test_calculates_max_extension_before_bounce(): void
    {
        $bars = [
            ['open' => 100, 'high' => 106, 'low' => 99, 'close' => 105, 'volume' => 1, 'date' => '2026-06-01'],
            ['open' => 105, 'high' => 104, 'low' => 100, 'close' => 101, 'volume' => 1, 'date' => '2026-06-02'],
            ['open' => 101, 'high' => 101, 'low' => 99, 'close' => 100.5, 'volume' => 1, 'date' => '2026-06-03'],
        ];

        $extension = PreBounceExtensionCalculator::calculate($bars, 100.0, 2.0);

        $this->assertEquals(3.0, $extension);
    }

    public function test_maps_health_care_alias_to_healthcare_etf(): void
    {
        config([
            'vestix.sector_mapping' => [
                'Healthcare' => 'XLV',
            ],
        ]);

        $this->mock(FinnhubService::class, function ($mock): void {
            $mock->shouldReceive('fetchCompanyProfile')
                ->once()
                ->with('JNJ')
                ->andReturn(['gsector' => 'Health Care', 'name' => 'Johnson & Johnson']);
        });

        $resolver = app(SectorTrendResolver::class);

        $this->assertSame('XLV', $resolver->resolveEtfTicker('JNJ', null));
    }

    public function test_override_skips_profile_lookup(): void
    {
        $resolver = app(SectorTrendResolver::class);

        $this->assertSame('XLF', $resolver->resolveEtfTicker('BAC', 'XLF'));
    }

    /**
     * @param  array<int, int>  $priorVolumes
     * @return array{
     *     today: array{open: float, high: float, low: float, close: float, volume: float},
     *     bars: array<int, array{open: float, high: float, low: float, close: float, volume: float, date: string}>,
     * }
     */
    private function barsPayload(int $todayVolume, array $priorVolumes): array
    {
        $bars = [];

        foreach ($priorVolumes as $index => $volume) {
            $bars[] = [
                'open' => 100.0,
                'high' => 101.0,
                'low' => 99.0,
                'close' => 100.5,
                'volume' => (float) $volume,
                'date' => '2026-06-'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT),
            ];
        }

        $bars[] = [
            'open' => 100.0,
            'high' => 101.0,
            'low' => 99.0,
            'close' => 100.5,
            'volume' => (float) $todayVolume,
            'date' => '2026-07-01',
        ];

        return [
            'today' => $bars[array_key_last($bars)],
            'bars' => $bars,
        ];
    }
}
