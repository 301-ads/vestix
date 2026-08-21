<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApiCredential extends Model
{
    use HasFactory;

    public const PROVIDER_IBKR_FLEX = 'ibkr_flex';

    protected $fillable = [
        'user_id',
        'provider',
        'encrypted_credentials',
    ];

    protected function casts(): array
    {
        return [
            'encrypted_credentials' => 'encrypted:array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function flexToken(): ?string
    {
        $token = $this->encrypted_credentials['token'] ?? null;

        return filled($token) ? (string) $token : null;
    }

    public function flexQueryId(): ?string
    {
        $queryId = $this->encrypted_credentials['query_id'] ?? null;

        return filled($queryId) ? (string) $queryId : null;
    }

    public function hasCompleteFlexCredentials(): bool
    {
        return $this->flexToken() !== null && $this->flexQueryId() !== null;
    }
}
