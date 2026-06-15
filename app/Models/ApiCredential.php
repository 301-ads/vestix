<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApiCredential extends Model
{
    use HasFactory;

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
}
