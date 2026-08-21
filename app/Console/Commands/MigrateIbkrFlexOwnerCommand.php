<?php

namespace App\Console\Commands;

use App\Models\BankrollCashflow;
use App\Models\BankrollSnapshot;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-time safety migration for multi-user IBKR Flex.
 *
 * Rollout:
 * 1. Deploy code (fan-out disabled).
 * 2. php artisan vestix:migrate-ibkr-flex-owner --email=you@example.com
 *    (dry-run by default — review owner vs others counts).
 * 3. Same command with --execute to attach env credentials.
 * 4. php artisan vestix:sync-ibkr --user=<owner-id>
 * 5. Same migrate command with --execute --clean-others once the report looks right.
 * 6. Env IBKR_FLEX_* may remain as backup; sync no longer uses them per user.
 */
class MigrateIbkrFlexOwnerCommand extends Command
{
    protected $signature = 'vestix:migrate-ibkr-flex-owner
        {--email= : Owner email (required unless --user=)}
        {--user= : Owner user id (required unless --email=)}
        {--execute : Apply attach (and --clean-others when set). Without this flag the command is dry-run only.}
        {--clean-others : After attach, wipe leaked Flex data on non-owner users}';

    protected $description = 'Attach env IBKR Flex credentials to one owner user; optionally clean leaked copies from others.';

    public function handle(): int
    {
        $owner = $this->resolveOwner();

        if ($owner === null) {
            return self::FAILURE;
        }

        $dryRun = ! (bool) $this->option('execute');
        $cleanOthers = (bool) $this->option('clean-others');

        $token = (string) config('vestix.ibkr.flex.token', '');
        $queryId = (string) config('vestix.ibkr.flex.query_id', '');

        $this->info($dryRun ? 'Dry-run (no writes).' : 'Executing writes.');
        $this->newLine();
        $this->table(
            ['Field', 'Value'],
            [
                ['Owner id', (string) $owner->id],
                ['Owner email', (string) $owner->email],
                ['Owner already connected', $owner->hasIbkrFlexConnection() ? 'yes' : 'no'],
                ['Env token present', $token !== '' ? 'yes' : 'no'],
                ['Env query id', $queryId !== '' ? $queryId : '—'],
                ['Clean others', $cleanOthers ? 'yes' : 'no'],
            ],
        );

        $this->renderOwnershipReport($owner);

        if ($token === '' || $queryId === '') {
            $this->error('IBKR_FLEX_TOKEN and IBKR_FLEX_QUERY_ID must be set in env to attach credentials.');

            return self::FAILURE;
        }

        if ($dryRun) {
            $this->newLine();
            $this->comment('Would attach env Flex credentials to owner api_credentials (provider=ibkr_flex).');
            $this->comment('Owner ibkr_*, bankroll_snapshots, cashflows, and positions stay untouched.');

            if ($cleanOthers) {
                $leaked = $this->leakedUsersQuery($owner)->count();
                $this->comment("Would clean leaked Flex data on {$leaked} other user(s) with ibkr_last_success_at.");
            }

            $this->newLine();
            $this->info('Re-run with --execute to apply. Add --clean-others only after the report looks correct.');

            return self::SUCCESS;
        }

        $owner->storeIbkrFlexCredentials($token, $queryId);
        $this->info("Attached Flex credentials to user [{$owner->id}] {$owner->email}.");

        if ($cleanOthers) {
            $cleaned = $this->cleanLeakedUsers($owner);
            $this->info("Cleaned leaked Flex data for {$cleaned} other user(s).");
        }

        $this->newLine();
        $this->info('Next: php artisan vestix:sync-ibkr --user='.$owner->id);

        return self::SUCCESS;
    }

    private function resolveOwner(): ?User
    {
        $email = $this->option('email');
        $userId = $this->option('user');

        if (! filled($email) && ! filled($userId)) {
            $this->error('Provide --email= or --user= for the owner account (no silent default).');

            return null;
        }

        if (filled($userId) && filled($email)) {
            $owner = User::query()->find($userId);

            if ($owner === null) {
                $this->error("User [{$userId}] not found.");

                return null;
            }

            if (strcasecmp((string) $owner->email, (string) $email) !== 0) {
                $this->error("--user={$userId} email [{$owner->email}] does not match --email={$email}.");

                return null;
            }

            return $owner;
        }

        if (filled($userId)) {
            $owner = User::query()->find($userId);

            if ($owner === null) {
                $this->error("User [{$userId}] not found.");

                return null;
            }

            return $owner;
        }

        $owner = User::query()->where('email', $email)->first();

        if ($owner === null) {
            $this->error("No user with email [{$email}].");

            return null;
        }

        return $owner;
    }

    private function renderOwnershipReport(User $owner): void
    {
        $ownerSnapshots = BankrollSnapshot::query()->where('user_id', $owner->id)->count();
        $ownerCashflows = BankrollCashflow::query()->where('user_id', $owner->id)->count();
        $ownerIbkrCashflows = BankrollCashflow::query()
            ->where('user_id', $owner->id)
            ->where('source', 'ibkr')
            ->count();

        $leakedUsers = $this->leakedUsersQuery($owner)->get();
        $otherSnapshots = BankrollSnapshot::query()
            ->where('user_id', '!=', $owner->id)
            ->count();
        $otherIbkrCashflows = BankrollCashflow::query()
            ->where('user_id', '!=', $owner->id)
            ->where('source', 'ibkr')
            ->count();

        $this->newLine();
        $this->info('Ownership report');
        $this->table(
            ['Metric', 'Owner', 'Others'],
            [
                ['bankroll_snapshots', (string) $ownerSnapshots, (string) $otherSnapshots],
                ['cashflows (all)', (string) $ownerCashflows, (string) BankrollCashflow::query()->where('user_id', '!=', $owner->id)->count()],
                ['cashflows source=ibkr', (string) $ownerIbkrCashflows, (string) $otherIbkrCashflows],
                ['ibkr_net_liquidation', $owner->ibkr_net_liquidation !== null ? (string) $owner->ibkr_net_liquidation : '—', '—'],
                ['Users with ibkr_last_success_at (leaked candidates)', '—', (string) $leakedUsers->count()],
            ],
        );

        if ($leakedUsers->isNotEmpty()) {
            $this->table(
                ['Other user id', 'Email', 'NLV', 'Last success'],
                $leakedUsers->map(fn (User $user): array => [
                    (string) $user->id,
                    (string) $user->email,
                    $user->ibkr_net_liquidation !== null ? (string) $user->ibkr_net_liquidation : '—',
                    $user->ibkr_last_success_at?->toDateTimeString() ?? '—',
                ])->all(),
            );
        }
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<User>
     */
    private function leakedUsersQuery(User $owner)
    {
        return User::query()
            ->where('id', '!=', $owner->id)
            ->whereNotNull('ibkr_last_success_at');
    }

    private function cleanLeakedUsers(User $owner): int
    {
        $users = $this->leakedUsersQuery($owner)->get();
        $cleaned = 0;

        foreach ($users as $user) {
            DB::transaction(function () use ($user): void {
                BankrollSnapshot::query()->where('user_id', $user->id)->delete();
                BankrollCashflow::query()
                    ->where('user_id', $user->id)
                    ->where('source', 'ibkr')
                    ->delete();

                $user->forceFill([
                    'ibkr_net_liquidation' => null,
                    'ibkr_available_funds' => null,
                    'ibkr_settled_cash' => null,
                    'ibkr_base_currency' => null,
                    'ibkr_open_positions' => null,
                    'ibkr_open_orders' => null,
                    'ibkr_last_success_at' => null,
                    'ibkr_last_attempt_at' => null,
                    'ibkr_last_error' => null,
                    'ibkr_data_stale' => false,
                    'trading_bankroll' => null,
                ])->save();
            });

            $cleaned++;
        }

        return $cleaned;
    }
}
