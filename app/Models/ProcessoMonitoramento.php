<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class ProcessoMonitoramento extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUS_ATIVO = 'ativo';
    public const STATUS_PAUSADO = 'pausado';
    public const STATUS_SUSPENSO = 'suspenso';
    public const STATUS_CANCELADO = 'cancelado';

    protected $table = 'processo_monitoramentos';

    protected $fillable = [
        'api_token_id',
        'tribunal_id',
        'numero_processo',
        'intervalo_horas',
        'credencial_id',
        'callback_url',
        'callback_token',
        'status',
        'proxima_execucao_em',
        'ultima_execucao_em',
        'data_referencia',
        'falhas_consecutivas',
        'bloqueado_ate',
    ];

    protected $hidden = [
        'id',
        'api_token_id',
        'credencial_id',
        'callback_token',
        'deleted_at',
    ];

    protected $casts = [
        'proxima_execucao_em' => 'datetime',
        'ultima_execucao_em' => 'datetime',
        'bloqueado_ate' => 'datetime',
        'intervalo_horas' => 'integer',
        'falhas_consecutivas' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            $model->uuid = $model->uuid ?? (string) Str::uuid();
        });
    }

    public function tribunal()
    {
        return $this->belongsTo(Tribunal::class);
    }

    public function credencial()
    {
        return $this->belongsTo(CredencialPje::class, 'credencial_id');
    }

    public function execucoes()
    {
        return $this->hasMany(ProcessoMonitoramentoExecucao::class, 'monitoramento_id')
            ->orderBy('id', 'desc');
    }

    public function scopeDoToken(Builder $query, int $apiTokenId): Builder
    {
        return $query->where('api_token_id', $apiTokenId);
    }

    public function scopeVencidos(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ATIVO)
            ->where('proxima_execucao_em', '<=', now())
            ->where(fn (Builder $q) => $q->whereNull('bloqueado_ate')->orWhere('bloqueado_ate', '<', now()));
    }
}
