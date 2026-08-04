@php
    /** @var \Illuminate\Support\Collection<int, array<string, mixed>> $rows */
@endphp

<div class="space-y-3 text-sm">
    <p class="text-gray-500 dark:text-gray-400">
        Privacy-safe: namen, status en ROI % — geen dollarbedragen.
    </p>

    @if ($rows->isEmpty())
        <p class="text-gray-600 dark:text-gray-300">Nog geen clones van deze setup.</p>
    @else
        <div class="overflow-hidden rounded-lg border border-gray-200 dark:border-white/10">
            <table class="w-full text-left">
                <thead class="bg-gray-50 text-xs uppercase tracking-wide text-gray-500 dark:bg-white/5 dark:text-gray-400">
                    <tr>
                        <th class="px-3 py-2 font-medium">Wie</th>
                        <th class="px-3 py-2 font-medium">Status</th>
                        <th class="px-3 py-2 font-medium">ROI %</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                    @foreach ($rows as $row)
                        <tr>
                            <td class="px-3 py-2 text-gray-900 dark:text-gray-100">{{ $row['cloner_name'] }}</td>
                            <td class="px-3 py-2 text-gray-700 dark:text-gray-300">
                                {{ $row['status_label'] }}
                                @if ($row['freeride'])
                                    <span class="text-xs text-success-600 dark:text-success-400">· freeride</span>
                                @endif
                            </td>
                            <td class="px-3 py-2 @if ($row['roi_pct'] !== null && $row['roi_pct'] >= 0) text-success-600 dark:text-success-400 @elseif ($row['roi_pct'] !== null) text-danger-600 dark:text-danger-400 @else text-gray-400 @endif">
                                @if ($row['roi_pct'] !== null)
                                    {{ number_format($row['roi_pct'], 2) }}%
                                @else
                                    —
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
