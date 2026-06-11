<?php

namespace App\Filament\Resources\Positions\Pages;

use App\Filament\Resources\Positions\PositionResource;
use App\Filament\Resources\Positions\Schemas\PositionForm;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Schema;

class CreateScout extends CreateRecord
{
    protected static string $resource = PositionResource::class;

    protected static ?string $title = 'Scout toevoegen';

    protected static ?string $breadcrumb = 'Scout toevoegen';

    public function form(Schema $schema): Schema
    {
        return PositionForm::configure($schema, scoutMode: true);
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['status'] = 'scout';

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit-scout', ['record' => $this->getRecord()]);
    }
}
