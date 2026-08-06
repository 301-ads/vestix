<?php

namespace App\Console\Commands;

use App\Models\Position;
use Illuminate\Console\Command;

class RepairCollapsedScaleOutQuantities extends Command
{
    protected $signature = 'positions:repair-collapsed-scale-outs
                            {--dry-run : Toon wat er zou worden hersteld zonder te schrijven}';

    protected $description = 'Herstel quantity wanneer die is ingeklapt tot de scale-out size (remaining=0), zodat runner-P&L weer meetelt';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $fixed = 0;

        Position::query()
            ->whereNotNull('scaled_out_at')
            ->whereNotNull('scaled_out_quantity')
            ->where('scaled_out_quantity', '>', 0)
            ->orderBy('id')
            ->each(function (Position $position) use ($dryRun, &$fixed): void {
                $before = (float) $position->quantity;
                $inferred = $position->inferredOriginalQuantityAfterCollapsedScaleOut();

                if ($inferred === null) {
                    return;
                }

                $this->line(sprintf(
                    '%s #%d %s: quantity %.4f → %.4f (scaled_out %.4f)',
                    $position->status,
                    $position->id,
                    $position->ticker,
                    $before,
                    $inferred,
                    (float) $position->scaled_out_quantity,
                ));

                if (! $dryRun) {
                    $position->repairCollapsedScaleOutQuantity();
                    $payload = ['quantity' => $position->quantity];

                    if ($position->status === 'closed' && $position->exit_price !== null) {
                        $payload['risk_reward_ratio'] = Position::computeBlendedRiskRewardRatio(
                            $position,
                            $position->exit_price,
                        );
                    }

                    $position->update($payload);
                }

                $fixed++;
            });

        $this->info($dryRun
            ? "Dry-run: {$fixed} positie(s) zouden hersteld worden."
            : "Hersteld: {$fixed} positie(s).");

        return self::SUCCESS;
    }
}
