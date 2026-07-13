<x-filament-widgets::widget class="vestix-actions-widget">
    <x-filament::section
        heading="Wekelijkse Bankroll Update"
        :compact="true"
    >
        <div class="vestix-action-todo vestix-action-todo--info">
            <div class="vestix-action-todo__content w-full">
                <p class="vestix-action-todo__ticker">💰 Bankroll bijwerken</p>
                <p class="vestix-action-todo__instruction">
                    Vul je actuele saldo in uit je broker (Revolut: Beleggingsrekening). SPY-benchmark wordt automatisch opgeslagen voor je Alpha Tracker.
                </p>

                <form wire:submit="saveBankroll" class="mt-4 flex flex-wrap items-end gap-3">
                    <div class="min-w-[12rem] flex-1">
                        <label class="text-sm font-medium text-gray-950 dark:text-white" for="bankrollAmount">
                            Nieuw saldo
                        </label>
                        <div class="mt-1 flex rounded-lg shadow-sm ring-1 ring-gray-950/10 dark:ring-white/20">
                            <span class="inline-flex items-center rounded-s-lg bg-gray-50 px-3 text-sm text-gray-500 dark:bg-white/5 dark:text-gray-400">$</span>
                            <input
                                id="bankrollAmount"
                                type="number"
                                step="0.01"
                                min="0.01"
                                wire:model="bankrollAmount"
                                class="block w-full rounded-e-lg border-0 bg-white px-3 py-2 text-sm text-gray-950 focus:ring-2 focus:ring-inset focus:ring-primary-600 dark:bg-gray-900 dark:text-white"
                            />
                        </div>
                    </div>

                    <x-filament::button type="submit" color="success" icon="heroicon-o-check">
                        Opslaan
                    </x-filament::button>
                </form>
            </div>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
