<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class ProcessoDocumento extends Model
{
    const STATUS_BAIXADO = 'baixado';
    const STATUS_PENDENTE = 'pendente';
    const STATUS_ERRO = 'erro';

    protected $table = 'processo_documentos';
    protected $fillable = [
        'processo_id',
        'id_documento',
        'id_documento_vinculado',
        'tipo_documento',
        'data_hora',
        'mimetype',
        'movimento',
        'hash',
        'descricao',
        'usuario_juntada_arquivo',
        'data_juntada',
        'status',
        'downloaded_at',
        'url',
        'path',
        'path_html',
        'file_size',
        'tentativas_download',
        'erro_mni',
    ];

    protected $casts = [
        'id_documento' => 'integer',
        'downloaded_at' => 'datetime',
    ];

    protected $hidden = [
        'id',
        'processo_id',
        'created_at',
        'updated_at',
        'deleted_at',
        'downloaded_at',
        'baixado',
        'url',
        'path',
        'path_html',
        'conteudo_html',
    ];

    public function temConteudoHtml(): bool
    {
        return !empty($this->conteudo_html) || !empty($this->path_html);
    }

    public function processo()
    {
        return $this->belongsTo(Processo::class, 'processo_id');
    }

    public function getTipoDocumento()
    {
        return TipoDocumento::where('codigo', $this->tipo_documento)
            ->where('tribunal_id', $this->processo->tribunal_id)
            ->first();
    }

    public function getUrlAttribute($value)
    {
        return Storage::url($this->path);
    }
}
