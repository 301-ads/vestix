<?php

namespace App\Filament\Forms\Components;

use App\Models\Squad;
use App\Models\User;
use App\Services\DiscoverableUserSearchService;
use Filament\Forms\Components\Select;

class UserPicker
{
    public static function make(string $name, ?Squad $excludeMembersOf = null, bool $multiple = true): Select
    {
        $select = Select::make($name)
            ->searchable()
            ->preload(false)
            ->getSearchResultsUsing(function (string $search) use ($excludeMembersOf): array {
                $actor = auth()->user();

                if (! $actor instanceof User) {
                    return [];
                }

                return app(DiscoverableUserSearchService::class)->search($search, $actor, $excludeMembersOf);
            })
            ->getOptionLabelUsing(function ($value): ?string {
                if (blank($value)) {
                    return null;
                }

                $labels = app(DiscoverableUserSearchService::class)->labelsFor($value);

                return $labels[$value] ?? null;
            })
            ->getOptionLabelsUsing(function (array $values): array {
                return app(DiscoverableUserSearchService::class)->labelsFor($values);
            });

        if ($multiple) {
            return $select->multiple();
        }

        return $select;
    }
}
