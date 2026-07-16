<?php
// app/Models/ApiToken.php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ApiToken extends Model
{
    /** @use HasFactory<\Database\Factories\ApiTokenFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'token',
        'ativo',
        'expires_at',
        'last_used_at',
    ];

    protected function casts(): array
    {
        return [
            'ativo' => 'boolean',
            'expires_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    public static function generatePlainToken(): string
    {
        return 'mni_' . Str::random(48);
    }

    public static function hashToken(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }

    public function scopeValido(Builder $query): Builder
    {
        return $query
            ->where('ativo', true)
            ->where(function (Builder $q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });
    }

    public static function findValid(string $plainToken): ?self
    {
        return static::query()
            ->valido()
            ->where('token', static::hashToken($plainToken))
            ->first();
    }

    public function registrarUso(): void
    {
        if ($this->last_used_at !== null && $this->last_used_at->gt(now()->subMinute())) {
            return;
        }

        static::withoutTimestamps(function () {
            $this->forceFill(['last_used_at' => now()])->saveQuietly();
        });
    }
}
