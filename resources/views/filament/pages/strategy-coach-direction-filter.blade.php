<div class="vestix-coach-edge">
    <div class="vestix-direction-segment vestix-direction-segment--filter" role="tablist" aria-label="Richting filter">
        @foreach ([
            'all' => ['label' => 'Alles', 'tone' => 'neutral'],
            'long' => ['label' => 'Longs', 'tone' => 'long'],
            'short' => ['label' => 'Shorts', 'tone' => 'short'],
        ] as $value => $option)
            <button
                type="button"
                role="tab"
                wire:click="setDirectionFilter('{{ $value }}')"
                @class([
                    'vestix-direction-segment__btn',
                    'vestix-direction-segment__btn--active' => $directionFilter === $value,
                    'vestix-direction-segment__btn--long' => $directionFilter === $value && $option['tone'] === 'long',
                    'vestix-direction-segment__btn--short' => $directionFilter === $value && $option['tone'] === 'short',
                ])
                aria-selected="{{ $directionFilter === $value ? 'true' : 'false' }}"
            >
                {{ $option['label'] }}
            </button>
        @endforeach
    </div>
</div>
