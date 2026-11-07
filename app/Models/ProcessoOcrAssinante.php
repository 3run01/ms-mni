<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Cada pedido de OCR aceito num processamento: o dono do par (que o abriu)
 * ou um carona. Guarda só o callback, nunca o par do PJe (RNF-01).
 */
class ProcessoOcrAssinante extends Model
{
    protected $table = 'processo_ocr_assinantes';

    protected $fillable = [
        'processamento_id',
        'api_token_id',
        'dono',
        'callback_url',
        'callback_token',
        'callback_hash',
        'encerrado_em',
        'aviso_final_entregue_em',
    ];

    protected $hidden = [
        'callback_token',
        'callback_hash',
    ];

    protected $casts = [
        'dono' => 'boolean',
        // O token é o X-API-Token do callback do cliente: cifrado em repouso.
        'callback_token' => 'encrypted',
        'encerrado_em' => 'datetime',
        'aviso_final_entregue_em' => 'datetime',
    ];

    protected static function booted(): void
    {
        // O hash sai sempre da URL e do token gravados: é ele que impede o
        // mesmo assinante duas vezes no processamento (unique com processamento_id).
        static::saving(function (self $assinante) {
            if ($assinante->isDirty(['callback_url', 'callback_token']) || empty($assinante->callback_hash)) {
                $assinante->callback_hash = self::hashCallback(
                    (string) $assinante->callback_url,
                    (string) $assinante->callback_token,
                );
            }
        });
    }

    public static function hashCallback(string $callbackUrl, string $callbackToken): string
    {
        return hash('sha256', $callbackUrl . "\n" . $callbackToken);
    }

    public function processamento(): BelongsTo
    {
        return $this->belongsTo(ProcessoOcrProcessamento::class, 'processamento_id');
    }

    public function avisos(): HasMany
    {
        return $this->hasMany(ProcessoOcrAviso::class, 'assinante_id');
    }

    /**
     * Ativo: não encerrado por 4xx (RF-16) e ainda sem o aviso final entregue (RF-15).
     */
    public function scopeAtivos(Builder $query): Builder
    {
        return $query->whereNull('encerrado_em')->whereNull('aviso_final_entregue_em');
    }
}
