<x-filament-widgets::widget>
    <x-filament::section
        heading="Edge analytics"
        description="Expectancy per setup-grade en protocol-score — actie, geen vanity."
        :compact="true"
    >
        @php($payload = $this->payload)

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div>
                <p class="text-xs text-gray-500 dark:text-gray-400">Win rate</p>
                <p class="text-lg font-semibold text-gray-950 dark:text-white">{{ number_format($payload['stats']['win_rate'], 1) }}%</p>
            </div>
            <div>
                <p class="text-xs text-gray-500 dark:text-gray-400">Expectancy</p>
                <p class="text-lg font-semibold text-gray-950 dark:text-white">{{ number_format($payload['stats']['expectancy'], 2) }}%</p>
            </div>
            <div>
                <p class="text-xs text-gray-500 dark:text-gray-400">Max drawdown</p>
                <p class="text-lg font-semibold text-gray-950 dark:text-white">{{ number_format($payload['stats']['max_drawdown'], 2) }}%</p>
            </div>
            <div>
                <p class="text-xs text-gray-500 dark:text-gray-400">Protocol avg</p>
                <p class="text-lg font-semibold text-gray-950 dark:text-white">
                    @if ($payload['protocol']['avg_score'] !== null)
                        {{ number_format($payload['protocol']['avg_score'], 0) }}/100
                    @else
                        —
                    @endif
                </p>
            </div>
        </div>

        @if ($payload['until_coach'] > 0)
            <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">
                Vestix Coach unlockt over {{ $payload['until_coach'] }} gesloten trade(s).
            </p>
        @endif

        @if ($payload['by_grade'] !== [])
            <div class="mt-4 overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-gray-500 dark:text-gray-400">
                            <th class="py-1 pr-3 font-medium">Grade</th>
                            <th class="py-1 pr-3 font-medium">Trades</th>
                            <th class="py-1 pr-3 font-medium">Win %</th>
                            <th class="py-1 font-medium">Expectancy</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($payload['by_grade'] as $row)
                            <tr class="border-t border-gray-200/10">
                                <td class="py-1.5 pr-3 text-gray-950 dark:text-white">{{ $row['grade'] }}</td>
                                <td class="py-1.5 pr-3">{{ $row['trades'] }}</td>
                                <td class="py-1.5 pr-3">{{ number_format($row['win_rate'], 1) }}%</td>
                                <td class="py-1.5">{{ number_format($row['expectancy'], 2) }}%</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
