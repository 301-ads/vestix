<?php

namespace Database\Seeders;

use App\Enums\VaultTransactionSource;
use App\Models\User;
use App\Models\VaultTransaction;
use App\Services\Kluis\VaultService;
use Illuminate\Database\Seeder;

class VaultTransactionSeeder extends Seeder
{
    /**
     * Broker fills from IBKR screenshot (May–Jul 2026).
     *
     * @var list<array{traded_at: string, shares: float, fill_price: float, etf_amount: float, fee: float}>
     */
    public const HISTORICAL_FILLS = [
        [
            'traded_at' => '2026-05-13 11:05:53',
            'shares' => 47.1105,
            'fill_price' => 159.20,
            'etf_amount' => 7499.99,
            'fee' => 4.50,
        ],
        [
            'traded_at' => '2026-06-10 09:40:12',
            'shares' => 46.8691,
            'fill_price' => 160.02,
            'etf_amount' => 7499.99,
            'fee' => 4.50,
        ],
        [
            'traded_at' => '2026-07-10 09:52:28',
            'shares' => 45.3233,
            'fill_price' => 165.48,
            'etf_amount' => 7500.00,
            'fee' => 4.50,
        ],
        [
            'traded_at' => '2026-07-24 14:58:16',
            'shares' => 23.3099,
            'fill_price' => 163.88,
            'etf_amount' => 3819.99,
            'fee' => 2.29,
        ],
        [
            'traded_at' => '2026-07-27 09:06:45',
            'shares' => 23.3381,
            'fill_price' => 164.97,
            'etf_amount' => 3850.00,
            'fee' => 2.31,
        ],
    ];

    public function run(): void
    {
        $user = User::query()->orderBy('id')->first();

        if ($user === null) {
            $this->command?->warn('No users — skipped VaultTransactionSeeder.');

            return;
        }

        app(VaultService::class)->settingsFor($user);

        foreach (self::HISTORICAL_FILLS as $fill) {
            $exists = VaultTransaction::query()
                ->where('user_id', $user->id)
                ->where('source', VaultTransactionSource::Historical)
                ->where('traded_at', $fill['traded_at'])
                ->where('shares', $fill['shares'])
                ->exists();

            if ($exists) {
                continue;
            }

            app(VaultService::class)->addHistoricalPurchase($user, [
                ...$fill,
                'ticker' => 'VWCE',
                'notes' => 'Geïmporteerd uit broker-transactiehistorie',
            ]);
        }
    }
}
