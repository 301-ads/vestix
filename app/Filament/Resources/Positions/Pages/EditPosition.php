<?php

namespace App\Filament\Resources\Positions\Pages;

use App\Filament\Resources\Positions\PositionResource;
use App\Filament\Resources\Positions\Tables\PositionRecordActions;
use App\Models\Position;
use App\Support\ScoutSetupScorecard;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

class EditPosition extends EditRecord
{
    protected static string $resource = PositionResource::class;

    public function mount(int | string $record): void
    {
        parent::mount($record);

        /** @var Position $position */
        $position = $this->getRecord();
        $pageName = static::getResourcePageName();

        if ($position->status === 'scout' && $pageName === 'edit') {
            $this->redirect(PositionResource::getUrl('edit-scout', ['record' => $record]));
        }

        if ($position->status !== 'scout' && $pageName === 'edit-scout') {
            $this->redirect(PositionResource::getUrl('edit', ['record' => $record]));
        }
    }

    protected function getHeaderActions(): array
    {
        /** @var Position $record */
        $record = $this->getRecord();

        if ($record->status === 'scout') {
            return [
                PositionRecordActions::fetchMarketData(),
                $this->scoutActivateAction(),
                DeleteAction::make(),
            ];
        }

        return [
            PositionRecordActions::fetchMarketData(),
            PositionRecordActions::markAsUpdated(),
            PositionRecordActions::archive(),
            DeleteAction::make(),
        ];
    }

    public function getTitle(): string | Htmlable
    {
        /** @var Position $record */
        $record = $this->getRecord();

        return new HtmlString(view('filament.positions.edit-page-heading', [
            'title' => $this->getRecordTitle(),
            'status' => $record->status,
        ])->render());
    }

    public function getSubheading(): string | Htmlable | null
    {
        /** @var Position $record */
        $record = $this->getRecord();

        if ($record->status !== 'closed' || $record->exit_price === null || $record->closed_at === null) {
            return null;
        }

        return sprintf(
            'Exit: $%s — gesloten op %s',
            number_format((float) $record->exit_price, 2),
            $record->closed_at->translatedFormat('j M Y'),
        );
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        unset($data['status'], $data['exit_price'], $data['closed_at']);

        return $data;
    }

    /**
     * @return array{
     *     totalPoints: int,
     *     maxPoints: int,
     *     grade: string,
     *     gradeLabel: string,
     *     hardFailReasons: array<int, string>,
     *     criteria: array<int, array<string, mixed>>,
     * }
     */
    protected function resolveSetupScoreFromForm(): array
    {
        /** @var Position $record */
        $record = $this->getRecord();

        return ScoutSetupScorecard::evaluate([
            'entry_price' => $this->data['entry_price'] ?? $record->entry_price,
            'latest_sma_20' => $this->data['latest_sma_20'] ?? $record->latest_sma_20,
            'sma_20_five_days_ago' => $this->data['sma_20_five_days_ago'] ?? $record->sma_20_five_days_ago,
            'latest_sma_50' => $this->data['latest_sma_50'] ?? $record->latest_sma_50,
            'scout_rsi' => $this->data['scout_rsi'] ?? $record->scout_rsi,
            'bounce_volume_above_average' => (bool) ($this->data['bounce_volume_above_average'] ?? $record->bounce_volume_above_average),
        ]);
    }

    protected function scoutActivateAction(): \Filament\Actions\Action
    {
        return PositionRecordActions::activateScout()
            ->color(fn (): string => $this->scoutActivateColor())
            ->extraAttributes(fn (): array => $this->scoutActivateExtraAttributes())
            ->tooltip(fn (): string => $this->scoutActivateTooltip());
    }

    protected function scoutActivateColor(): string
    {
        $score = $this->resolveSetupScoreFromForm();

        if ($score['hardFailReasons'] !== []) {
            return 'gray';
        }

        if ($score['totalPoints'] === 7) {
            return 'success';
        }

        if ($score['totalPoints'] >= 5) {
            return 'success';
        }

        return 'warning';
    }

    /**
     * @return array<string, string>
     */
    protected function scoutActivateExtraAttributes(): array
    {
        $score = $this->resolveSetupScoreFromForm();

        if ($score['hardFailReasons'] === [] && $score['totalPoints'] === 7) {
            return ['class' => 'scout-activate-a-plus'];
        }

        return [];
    }

    protected function scoutActivateTooltip(): string
    {
        $score = $this->resolveSetupScoreFromForm();

        if ($score['hardFailReasons'] !== []) {
            return implode(' ', $score['hardFailReasons']);
        }

        return match (true) {
            $score['totalPoints'] === 7 => 'A+ SETUP — mathematisch perfecte trade',
            $score['totalPoints'] >= 5 => 'A- Setup — overweeg halve positie',
            default => 'B/C Setup — overweeg niet te activeren',
        };
    }
}
