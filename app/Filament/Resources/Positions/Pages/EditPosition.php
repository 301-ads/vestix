<?php

namespace App\Filament\Resources\Positions\Pages;

use App\Enums\PositionVisibility;
use App\Events\SquadRadarTargetPosted;
use App\Filament\Concerns\PollsPositionMarketData;
use App\Filament\Resources\Positions\PositionResource;
use App\Filament\Resources\Positions\Tables\PositionRecordActions;
use App\Filament\Resources\Scouts\ScoutResource;
use App\Models\Position;
use App\Models\Squad;
use App\Services\AssetSyncService;
use App\Services\SquadContext;
use App\Support\MarketDataFreshness;
use App\Support\ScoutSetupScorecard;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\HtmlString;

class EditPosition extends EditRecord
{
    use PollsPositionMarketData;

    protected static string $resource = PositionResource::class;

    /**
     * @return array<string>
     */
    public function getPageClasses(): array
    {
        return [
            'fi-resource-edit-record-page',
            'vestix-position-edit',
        ];
    }

    public function mountCanAuthorizeAccess(): void
    {
        if (! $this->record instanceof Model) {
            return;
        }

        abort_unless(static::canAccess(['record' => $this->getRecord()]), 403);
    }

    public function mount(int|string $record): void
    {
        $position = Position::query()->findOrFail($record);

        if ($position->status === 'scout' && static::$resource === PositionResource::class) {
            throw new HttpResponseException(
                new RedirectResponse(ScoutResource::getUrl('edit', ['record' => $record])),
            );
        }

        if ($position->status !== 'scout' && static::$resource === ScoutResource::class) {
            throw new HttpResponseException(
                new RedirectResponse(PositionResource::getUrl('edit', ['record' => $record])),
            );
        }

        parent::mount($record);

        /** @var Position $position */
        $position = $this->getRecord();
        $position->loadMissing('asset');

        if ($position->asset && ! $position->asset->hasIcon()) {
            app(AssetSyncService::class)->ensureForTicker($position->ticker);
            $position->load('asset');
        }

        if (MarketDataFreshness::isPositionSyncInProgress($position->id)) {
            $this->startPollingPositionMarketData();
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

    public function getTitle(): string|Htmlable
    {
        /** @var Position $record */
        $record = $this->getRecord();

        return new HtmlString(view('filament.positions.edit-page-heading', [
            'title' => $this->getRecordTitle(),
            'status' => $record->status,
            'iconUrl' => $record->asset?->icon_url,
        ])->render());
    }

    public function getSubheading(): string|Htmlable|null
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

        if (($this->getRecord()->status ?? null) === 'scout') {
            $visibility = PositionVisibility::tryFrom((string) ($data['visibility'] ?? ''))
                ?? PositionVisibility::Private;

            $user = auth()->user();
            $squadId = isset($data['squad_id']) ? (int) $data['squad_id'] : null;
            $squad = $user && $squadId
                ? $user->squads()->whereKey($squadId)->first()
                : null;

            if (
                $visibility === PositionVisibility::Squad
                && $user !== null
                && $squad instanceof Squad
                && app(SquadContext::class)->userCanInSquad($user, $squad, 'scout.share')
            ) {
                $data['visibility'] = PositionVisibility::Squad->value;
                $data['squad_id'] = $squad->id;
            } else {
                $data['visibility'] = PositionVisibility::Private->value;
                $data['squad_id'] = null;
            }
        }

        return $data;
    }

    protected function afterSave(): void
    {
        /** @var Position $record */
        $record = $this->getRecord();

        if ($record->status !== 'scout' || $record->visibility !== PositionVisibility::Squad) {
            return;
        }

        if ($record->wasChanged('visibility') || $record->wasRecentlyCreated) {
            SquadRadarTargetPosted::dispatch($record);
        }
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
            'signal_low' => $this->data['signal_low'] ?? $record->signal_low,
            'latest_close_price' => $this->data['latest_close_price'] ?? $record->latest_close_price,
            'latest_sma_20' => $this->data['latest_sma_20'] ?? $record->latest_sma_20,
            'sma_20_five_days_ago' => $this->data['sma_20_five_days_ago'] ?? $record->sma_20_five_days_ago,
            'latest_sma_50' => $this->data['latest_sma_50'] ?? $record->latest_sma_50,
            'scout_rsi' => $this->data['scout_rsi'] ?? $record->scout_rsi,
            'bounce_volume_above_average' => (bool) ($this->data['bounce_volume_above_average'] ?? $record->bounce_volume_above_average),
        ]);
    }

    protected function scoutActivateAction(): Action
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
