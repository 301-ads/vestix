<?php

namespace App\Filament\Resources\Positions\Pages;

use App\Enums\PositionVisibility;
use App\Events\SquadRadarTargetPosted;
use App\Filament\Resources\Positions\Schemas\PositionForm;
use App\Filament\Resources\Scouts\ScoutResource;
use App\Models\Squad;
use App\Services\SquadContext;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Schema;

class CreateScout extends CreateRecord
{
    protected static string $resource = ScoutResource::class;

    protected static ?string $title = 'Scout toevoegen';

    protected static ?string $breadcrumb = 'Scout toevoegen';

    public function form(Schema $schema): Schema
    {
        return PositionForm::configure($schema, scoutMode: true);
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['status'] = 'scout';
        $data['user_id'] = auth()->id();

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

        return $data;
    }

    protected function afterCreate(): void
    {
        $record = $this->getRecord();

        if ($record->visibility === PositionVisibility::Squad) {
            SquadRadarTargetPosted::dispatch($record);
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
