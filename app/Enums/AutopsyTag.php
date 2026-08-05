<?php

namespace App\Enums;

enum AutopsyTag: string
{
    case QuarantineBreach = 'quarantine_breach';
    case NearMissBlockade = 'near_miss_blockade';
    case MicroManagement = 'micro_management';
    case FlawlessExecution = 'flawless_execution';

    public function label(): string
    {
        return match ($this) {
            self::QuarantineBreach => 'Quarantaine-Breuk',
            self::NearMissBlockade => 'Near-Miss / Blokkade',
            self::MicroManagement => 'Micro-Management',
            self::FlawlessExecution => 'Flawless Execution',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::QuarantineBreach => 'Instap rondom earnings of fundamenteel nieuws',
            self::NearMissBlockade => 'Scorecard niet gerespecteerd / FOMO-selectie',
            self::MicroManagement => 'Stop te vroeg verplaatst of emotionele exit',
            self::FlawlessExecution => '100% volgens de regels — uitkomst is wiskunde',
        };
    }

    public function isError(): bool
    {
        return $this !== self::FlawlessExecution;
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label().' — '.$case->description();
        }

        return $options;
    }
}
