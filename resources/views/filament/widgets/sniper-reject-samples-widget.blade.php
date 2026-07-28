<x-filament-widgets::widget>
    <x-filament::section
        heading="Sniper afwijzingen (laatste scan)"
        description="Waarom liquid tickers de math-filter misten — leert sneller dan meer scouts."
        :compact="true"
    >
        @php($payload = $this->payload)

        @if ($payload['date'])
            <p class="mb-2 text-xs text-gray-500 dark:text-gray-400">Scan {{ $payload['date'] }}</p>
        @endif

        <ul class="space-y-2 text-sm">
            @foreach (array_slice($payload['samples'], 0, 8) as $sample)
                <li class="rounded-lg bg-gray-500/5 px-3 py-2 dark:bg-white/5">
                    <span class="font-medium text-gray-950 dark:text-white">{{ $sample['ticker'] }}</span>
                    <span class="text-gray-500 dark:text-gray-400"> — {{ implode(' · ', $sample['reasons'] ?? []) }}</span>
                </li>
            @endforeach
        </ul>
    </x-filament::section>
</x-filament-widgets::widget>
