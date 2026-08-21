<?php

namespace App\Support;

use App\Models\User;

class FirstRunChecklist
{
    public const STEPS = [
        'risk_percent',
        'alerts',
        'bankroll',
    ];

    /**
     * @return array{
     *     completed: bool,
     *     dismissed: bool,
     *     steps: array<string, array{key: string, label: string, done: bool, hint: string}>,
     *     done_count: int,
     *     total: int
     * }
     */
    public static function status(User $user): array
    {
        $prefs = self::preferences($user);
        $dismissed = (bool) ($prefs['first_run']['dismissed'] ?? false);
        $completedAt = $prefs['first_run']['completed_at'] ?? null;

        $steps = [
            'risk_percent' => [
                'key' => 'risk_percent',
                'label' => 'Risico per trade',
                'done' => $user->default_risk_percent !== null,
                'hint' => 'Stel je long risico-niveau in (bijv. 1%).',
            ],
            'alerts' => [
                'key' => 'alerts',
                'label' => 'Telegram of Web Push',
                'done' => $user->hasTelegramConnection() || $user->hasPushSubscription(),
                'hint' => 'Koppel Telegram of activeer push voor digests.',
            ],
            'bankroll' => [
                'key' => 'bankroll',
                'label' => 'Bankroll / IBKR Flex',
                'done' => $user->trading_bankroll !== null
                    || $user->ibkr_last_success_at !== null
                    || $user->hasIbkrFlexConnection(),
                'hint' => 'Koppel IBKR Flex (token + Query ID) onder Trading Voorkeuren, of vul NLV handmatig in.',
            ],
        ];

        $doneCount = count(array_filter($steps, fn (array $step): bool => $step['done']));
        $completed = $completedAt !== null || $doneCount === count($steps);

        return [
            'completed' => $completed,
            'dismissed' => $dismissed,
            'steps' => $steps,
            'done_count' => $doneCount,
            'total' => count($steps),
        ];
    }

    public static function shouldShow(User $user): bool
    {
        $status = self::status($user);

        return ! $status['completed'] && ! $status['dismissed'];
    }

    public static function dismiss(User $user): void
    {
        self::mergePreferences($user, [
            'first_run' => [
                'dismissed' => true,
                'dismissed_at' => now()->toIso8601String(),
            ],
        ]);
    }

    public static function markCompletedIfReady(User $user): void
    {
        $status = self::status($user);

        if ($status['done_count'] < $status['total']) {
            return;
        }

        self::mergePreferences($user, [
            'first_run' => [
                'completed_at' => now()->toIso8601String(),
                'dismissed' => false,
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function preferences(User $user): array
    {
        return is_array($user->ui_preferences) ? $user->ui_preferences : [];
    }

    /**
     * @param  array<string, mixed>  $patch
     */
    public static function mergePreferences(User $user, array $patch): void
    {
        $current = self::preferences($user);
        $merged = array_replace_recursive($current, $patch);

        $user->forceFill(['ui_preferences' => $merged])->save();
    }
}
