<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProcessoExportacao extends Model
{
    use HasFactory;

    public const STATUS_ENFILEIRADO = 'enfileirado';
    public const STATUS_PROCESSANDO = 'processando';
    public const STATUS_CONCLUIDO = 'concluido';
    public const STATUS_FALHOU = 'falhou';

    public const FORMATO_PDF = 'pdf';

    protected $table = 'processo_exportacoes';

    protected $fillable = [
        'user_id',
        'numero_processo',
        'tribunal_id',
        'titulo',
        'formato',
        'status',
        'uuid_arquivo',
        's3_path',
        'tamanho_bytes',
        'erro_resumo',
        'filtros',
        'webhook_enviado_em',
        'webhook_tentativas',
    ];

    protected $casts = [
        'filtros' => 'array',
        'webhook_enviado_em' => 'datetime',
        'tamanho_bytes' => 'integer',
        'webhook_tentativas' => 'integer',
    ];

    public function jaFoiNotificado(): bool
    {
        return $this->webhook_enviado_em !== null;
    }
}
