<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class CredencialPje extends Model
{
    use HasFactory;

    protected $table = 'credenciais_pje';

    protected $fillable = [
        'api_token_id',
        'tribunal_id',
        'login',
        'senha',
        'login_hash',
        'ativo',
    ];

    protected $hidden = [
        'id',
        'login',
        'senha',
        'login_hash',
        'api_token_id',
    ];

    protected $casts = [
        'login' => 'encrypted',
        'senha' => 'encrypted',
        'ativo' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            $model->uuid = $model->uuid ?? (string) Str::uuid();
        });
    }

    public static function hashLogin(string $login): string
    {
        return hash('sha256', $login);
    }

    public function tribunal()
    {
        return $this->belongsTo(Tribunal::class);
    }

    public function getLoginMascaradoAttribute(): string
    {
        $login = (string) $this->login;

        if (mb_strlen($login) < 8) {
            return '******';
        }

        return mb_substr($login, 0, 3) . str_repeat('*', mb_strlen($login) - 6) . mb_substr($login, -3);
    }
}
