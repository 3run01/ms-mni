<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Notificacao extends Model
{
    const TIPO_DOWNLOAD_PROCESSO = 'DownloadProcesso';

    protected $table = 'notificacoes';

    protected $fillable = [
        'processo_id',
        'notificacao_id',
        'tipo',
        'notificado',
    ];

    protected $casts = [
        'notificado' => 'boolean',
    ];

    public function processo()
    {
        return $this->belongsTo(Processo::class);
    }
}
