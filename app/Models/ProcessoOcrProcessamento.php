<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * OCR de um processo numa linha (`processo_id` já implica o `tribunal_id`),
 * do pedido que o abre até o aviso final. No máximo 1 em andamento por
 * processo: índice único parcial `processo_ocr_processamentos_aberto_unico`.
 */
class ProcessoOcrProcessamento extends Model
{
    public const SITUACAO_EM_ANDAMENTO = 'em_andamento';
    public const SITUACAO_CONCLUIDO = 'concluido';
    public const SITUACAO_FALHOU = 'falhou';

    protected $table = 'processo_ocr_processamentos';

    protected $fillable = [
        'processo_id',
        'situacao',
        'codigo_falha',
        'aberto_em',
        'ultimo_progresso_em',
        'finalizado_em',
    ];

    protected $attributes = [
        'situacao' => self::SITUACAO_EM_ANDAMENTO,
    ];

    protected $casts = [
        'aberto_em' => 'datetime',
        'ultimo_progresso_em' => 'datetime',
        'finalizado_em' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $processamento) {
            $processamento->aberto_em ??= now();
        });
    }

    public function processo(): BelongsTo
    {
        return $this->belongsTo(Processo::class, 'processo_id');
    }

    public function assinantes(): HasMany
    {
        return $this->hasMany(ProcessoOcrAssinante::class, 'processamento_id');
    }

    public function scopeEmAndamento(Builder $query): Builder
    {
        return $query->where('situacao', self::SITUACAO_EM_ANDAMENTO);
    }
}
