<?php

namespace App\Filament\Resources\Positions\Pages;

use App\Filament\Resources\Scouts\ScoutResource;
use App\Models\Position;

class EditScout extends EditPosition
{
    protected static string $resource = ScoutResource::class;

    public function getResourceBreadcrumbs(): array
    {
        return [
            ListScouts::getUrl() => 'Mijn Radar',
        ];
    }

    public function getBreadcrumbs(): array
    {
        $breadcrumbs = $this->getResourceBreadcrumbs();

        /** @var Position $record */
        $record = $this->getRecord();

        $breadcrumbs[
            ScoutResource::getUrl('edit', ['record' => $record])
        ] = $this->getRecordTitle();

        $breadcrumbs[] = $this->getBreadcrumb();

        return $breadcrumbs;
    }
}
