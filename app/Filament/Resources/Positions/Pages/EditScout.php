<?php

namespace App\Filament\Resources\Positions\Pages;

use App\Filament\Resources\Positions\PositionResource;
use App\Models\Position;

class EditScout extends EditPosition
{
    public function getResourceBreadcrumbs(): array
    {
        return [
            ListScouts::getUrl() => 'Setup Radar',
        ];
    }

    public function getBreadcrumbs(): array
    {
        $breadcrumbs = $this->getResourceBreadcrumbs();

        /** @var Position $record */
        $record = $this->getRecord();

        $breadcrumbs[
            PositionResource::getUrl('edit-scout', ['record' => $record])
        ] = $this->getRecordTitle();

        $breadcrumbs[] = $this->getBreadcrumb();

        return $breadcrumbs;
    }
}
