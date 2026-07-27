@php
    use App\Enums\VaultTransactionSource;
    use App\Models\VaultTransaction;
    use Illuminate\Support\Collection;

    /** @var Collection<int, VaultTransaction> $transactions */
@endphp

<div class="vestix-kluis-transactions space-y-3">
    @forelse ($transactions as $transaction)
        <div class="vestix-kluis-transaction-row flex flex-wrap items-center justify-between gap-3 rounded-xl border border-gray-200 bg-white px-4 py-3 dark:border-gray-700 dark:bg-gray-900">
            <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="text-sm font-semibold text-gray-950 dark:text-white">
                        {{ $transaction->traded_at->format('j M Y H:i') }}
                    </span>
                    <span @class([
                        'inline-flex rounded-md px-1.5 py-0.5 text-xs font-medium',
                        'bg-primary-50 text-primary-700 dark:bg-primary-500/10 dark:text-primary-400' => $transaction->source === VaultTransactionSource::Historical,
                        'bg-success-50 text-success-700 dark:bg-success-500/10 dark:text-success-400' => $transaction->source === VaultTransactionSource::MonthlyConfirm,
                    ])>
                        {{ $transaction->source->label() }}
                    </span>
                </div>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    {{ number_format((float) $transaction->shares, 4, ',', '.') }} × {{ strtoupper((string) $transaction->ticker) }}
                    @ €{{ number_format((float) $transaction->fill_price, 2, ',', '.') }}
                    · €{{ number_format((float) $transaction->etf_amount, 2, ',', '.') }}
                    @if ((float) $transaction->fee > 0)
                        · fee €{{ number_format((float) $transaction->fee, 2, ',', '.') }}
                    @endif
                </p>
            </div>

            @if ($transaction->source === VaultTransactionSource::Historical)
                <div class="flex items-center gap-2">
                    <x-filament::button
                        color="gray"
                        size="sm"
                        outlined
                        wire:click="mountAction('editHistoricalPurchase', { transaction: {{ $transaction->id }} })"
                    >
                        Bewerken
                    </x-filament::button>
                    <x-filament::button
                        color="danger"
                        size="sm"
                        outlined
                        wire:click="mountAction('deleteHistoricalPurchase', { transaction: {{ $transaction->id }} })"
                    >
                        Verwijderen
                    </x-filament::button>
                </div>
            @endif
        </div>
    @empty
        <p class="text-sm text-gray-500 dark:text-gray-400">
            Nog geen aankopen. Voeg historische VWCE-fills toe of bevestig een maandbevel met fill-gegevens.
        </p>
    @endforelse
</div>
