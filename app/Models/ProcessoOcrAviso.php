<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Um aviso `ocr_progresso` para um assinante. O payload é congelado na
 * geração, com as contagens do momento (RF-14), e o `uuid` vai no
 * X-Idempotency-Key de todas as tentativas do mesmo aviso.
 */
class ProcessoOcrAviso extends Model
{
    protected $table = 'processo_ocr_avisos';

    protected $fillable = [
        'uuid',
        'assinante_id',
        'final',
        'payload',
        'enviado_em',
        'tentativas',
        'status_http',
    ];

    protected $casts = [
        'final' => 'boolean',
        'payload' => 'array',
        'enviado_em' => 'datetime',
        'tentativas' => 'integer',
        'status_http' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $aviso) {
            $aviso->uuid = $aviso->uuid ?? (string) Str::uuid();
        });
    }

    public function assinante(): BelongsTo
    {
        return $this->belongsTo(ProcessoOcrAssinante::class, 'assinante_id');
    }
}
