<?php

namespace App\Services;

use App\Models\Squad;
use App\Models\User;

class DiscoverableUserSearchService
{
    private const int MIN_QUERY_LENGTH = 2;

    private const int RESULT_LIMIT = 20;

    /**
     * @return array<int|string, string>
     */
    public function search(string $query, User $actor, ?Squad $excludeMembersOf = null): array
    {
        $query = trim($query);

        if (mb_strlen($query) < self::MIN_QUERY_LENGTH) {
            return [];
        }

        $escapedQuery = addcslashes($query, '%_\\');

        return User::query()
            ->where('is_discoverable', true)
            ->whereKeyNot($actor->id)
            ->when(
                $excludeMembersOf instanceof Squad,
                fn ($builder) => $builder->whereNotIn(
                    'id',
                    $excludeMembersOf->users()->pluck('users.id'),
                ),
            )
            ->where(function ($builder) use ($escapedQuery): void {
                $builder
                    ->where('name', 'like', "%{$escapedQuery}%")
                    ->orWhere('email', 'like', "%{$escapedQuery}%");
            })
            ->orderBy('name')
            ->limit(self::RESULT_LIMIT)
            ->get()
            ->mapWithKeys(fn (User $user): array => [
                $user->id => "{$user->name} ({$user->email})",
            ])
            ->all();
    }

    /**
     * @param  array<int|string>|int|string|null  $values
     * @return array<int|string, string>
     */
    public function labelsFor(array|int|string|null $values): array
    {
        if ($values === null || $values === [] || $values === '') {
            return [];
        }

        $ids = collect(is_array($values) ? $values : [$values])
            ->filter(fn (mixed $value): bool => filled($value))
            ->map(fn (mixed $value): int => (int) $value)
            ->unique()
            ->values()
            ->all();

        if ($ids === []) {
            return [];
        }

        return User::query()
            ->whereIn('id', $ids)
            ->get()
            ->mapWithKeys(fn (User $user): array => [
                $user->id => "{$user->name} ({$user->email})",
            ])
            ->all();
    }
}
