<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ProcessoMonitoramentoExecucao extends Model
{
    use HasFactory;

    public const STATUS_SUCESSO = 'sucesso';
    public const STATUS_FALHA = 'falha';

    protected $table = 'processo_monitoramento_execucoes';

    protected $fillable = [
        'monitoramento_id',
        'iniciado_em',
        'finalizado_em',
        'status',
        'houve_alteracao',
        'movimentos_novos',
        'documentos_novos',
        'delta',
        'erro_resumo',
        'webhook_enviado_em',
        'webhook_tentativas',
        'webhook_status_http',
    ];

    protected $hidden = [
        'id',
        'delta',
    ];

    protected $casts = [
        'iniciado_em' => 'datetime',
        'finalizado_em' => 'datetime',
        'webhook_enviado_em' => 'datetime',
        'houve_alteracao' => 'boolean',
        'delta' => 'array',
        'movimentos_novos' => 'integer',
        'documentos_novos' => 'integer',
        'webhook_tentativas' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            $model->uuid = $model->uuid ?? (string) Str::uuid();
        });
    }

    public function monitoramento()
    {
        return $this->belongsTo(ProcessoMonitoramento::class, 'monitoramento_id');
    }

    public function jaFoiNotificado(): bool
    {
        return $this->webhook_enviado_em !== null;
    }
}
