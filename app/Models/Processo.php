<?php

namespace App\Models;

// use App\Traits\LogsActivityOptionsTrait;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Processo extends Model
{
    use HasFactory;

    // Retorna created_at no timezone America/Sao_Paulo
    public function getCreatedAtAttribute($value)
    {
        return \Carbon\Carbon::parse($value)->setTimezone('America/Sao_Paulo')->toIso8601String();
    }

    // Retorna updated_at no timezone America/Sao_Paulo
    public function getUpdatedAtAttribute($value)
    {
        return \Carbon\Carbon::parse($value)->setTimezone('America/Sao_Paulo')->toIso8601String();
    }

    use HasFactory;

    const STATUS_PENDENTE_ENVIO = 'Pendente de envio';
    const STATUS_PROCESSANDO_ENVIO = 'Processando envio';
    const STATUS_PETICIONADO = 'Peticionado';
    const STATUS_ARQUIVADO = 'Arquivado';

    const KNOWLEDGE_BASE_STATUS_PENDING = 'PENDING';
    const KNOWLEDGE_BASE_STATUS_STARTING = 'STARTING';
    const KNOWLEDGE_BASE_STATUS_IN_PROGRESS = 'IN_PROGRESS';
    const KNOWLEDGE_BASE_STATUS_COMPLETE = 'COMPLETE';
    const KNOWLEDGE_BASE_STATUS_FAILED = 'FAILED';
    const KNOWLEDGE_BASE_STATUS_STOPPING = 'STOPPING';
    const KNOWLEDGE_BASE_STATUS_STOPPED = 'STOPPED';
    const KNOWLEDGE_BASE_STATUS_UNKNOWN = 'UNKNOWN';

    const OCR_STATUS_PENDENTE = 'PENDENTE';
    const OCR_STATUS_PROCESSANDO = 'PROCESSANDO';
    const OCR_STATUS_CONCLUIDO = 'CONCLUIDO';
    const OCR_STATUS_FALHA = 'FALHA';

    protected $logName = 'Processo';
    protected $table = 'processos';
    protected $with = [
        'tribunal',
        // 'partes',
        'prioridades',
        'classe',
        'assuntos',
        // 'movimentos',
        // 'documentos'
    ];
    protected $fillable = [
        'tribunal_id',
        'unidade_id',
        'numero_processo',
        'jurisdicao_codigo',
        'classe_codigo',
        'assunto_codigo',
        'competencia_codigo',
        'prioridade',
        'valor_causa',
        'nivel_sigilo',
        'status',
        'justica_gratuita',
        'pedido_liminar',
        'motivo_segredo_justica',
        'tentantivas_envio',
        'payload_envio',
        'tipo_eleicao',
        'nome_orgao_julgador',
        'codigo_orgao_julgador',
        'instancia_orgao_julgador',
        'knowledge_base_status_sync',
        'knowledge_base_sequence_job',
        'knowledge_base_created_at',
        'ocr_status',
        // 'knowledge_base_ingestion_job_id',
    ];

    protected $hidden = [
        'id',
        'deleted_at',
        'payload_envio',
        'tentativas_envio',
        'prioridade',
        'tribunal_id',
        'unidade_id',
        'tipo_eleicao',
    ];

    protected $casts = [
        'prioridade' => 'array',
    ];

    public function tribunal()
    {
        return $this->belongsTo(Tribunal::class)->where('ativo', true);
    }

    public function classe()
    {
        return $this->belongsTo(ClasseCNJ::class, 'classe_codigo', 'codigo');
    }

    public function prioridades()
    {
        return $this->hasMany(ProcessoPrioridade::class, 'processo_id');
    }

    public function assuntos()
    {
        return $this->hasMany(ProcessoAssunto::class, 'processo_id');
    }

    public function movimentos()
    {
        return $this->hasMany(ProcessoMovimento::class, 'processo_id')
            ->orderBy('data_hora', 'desc');
    }

    public function documentos()
    {
        return $this->hasMany(ProcessoDocumento::class, 'processo_id')
            ->orderBy('data_hora', 'desc');
    }

    public static function niveisSigilo()
    {
        return [
            0 => 'Não sigiloso',
            1 => '1 - Segredo de Justiça',
            2 => '2 - Sigilo Mínimo',
            3 => '3 - Sigilo Médio',
            4 => '4 - Sigilo Intenso',
            5 => '5 - Sigilo Absoluto'
        ];
    }


    public function partes()
    {
        return $this->hasMany(ProcessoParte::class, 'processo_id');
    }

    public function notificacoes()
    {
        return $this->hasMany(Notificacao::class, 'processo_id');
    }

    public static function statusColor()
    {
        return [
            self::STATUS_PENDENTE_ENVIO => [
                'text' => Self::STATUS_PENDENTE_ENVIO,
                'style' => 'background-color: #EF4444; color: white;',
            ],

            self::STATUS_PETICIONADO => [
                'text' => Self::STATUS_PETICIONADO,
                'style' => 'background-color: #16A34A; color: white;',
            ],
            self::STATUS_PROCESSANDO_ENVIO => [
                'text' => Self::STATUS_PROCESSANDO_ENVIO,
                'style' => 'background-color: #CA8A04; color: black;',
            ],

        ];
    }

    public static function getStatus()
    {
        return [
            self::STATUS_PENDENTE_ENVIO => 'Pendente de envio',
            self::STATUS_PETICIONADO => 'Peticionado',
            self::STATUS_PROCESSANDO_ENVIO => 'Processando envio'
        ];
    }
}
