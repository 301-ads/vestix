<?php

namespace App\Services\Kluis;

use App\Models\User;
use App\Models\VaultSetting;
use App\Support\FilamentNotifier;
use App\Support\TelegramNotifier;
use Illuminate\Support\Carbon;

class KluisMonthReminderService
{
    public function __construct(
        private VaultService $vault,
    ) {}

    /**
     * @return array{sent: int, skipped: int}
     */
    public function run(?Carbon $now = null): array
    {
        $sent = 0;
        $skipped = 0;
        $now ??= now('Europe/Amsterdam');

        // Remind from the 10th onward — matches the typical Smart DCA buy day.
        if ($now->day < 10) {
            return ['sent' => 0, 'skipped' => 0];
        }

        VaultSetting::query()
            ->with('user')
            ->cursor()
            ->each(function (VaultSetting $settings) use ($now, &$sent, &$skipped): void {
                $user = $settings->user;

                if (! $user instanceof User) {
                    $skipped++;

                    return;
                }

                if ($this->vault->monthAlreadyConfirmed($user, $now)) {
                    $skipped++;

                    return;
                }

                $monthLabel = $now->translatedFormat('F Y');
                $title = 'Vestix Kluis — maand nog open';
                $body = "Je hebt {$monthLabel} nog niet bevestigd in de Kluis. Open Vestix Kluis, ververs de thermometer en voer je Smart DCA-bevel uit.";

                FilamentNotifier::send(
                    title: $title,
                    body: $body,
                    status: 'warning',
                    recipients: $user,
                );

                if ($user->hasTelegramConnection()) {
                    TelegramNotifier::sendToUser(
                        $user,
                        "<b>{$title}</b>\n{$body}",
                    );
                }

                $sent++;
            });

        return compact('sent', 'skipped');
    }
}
