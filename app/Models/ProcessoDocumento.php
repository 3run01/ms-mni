<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class ProcessoDocumento extends Model
{
    const STATUS_BAIXADO = 'baixado';
    const STATUS_PENDENTE = 'pendente';
    const STATUS_ERRO = 'erro';

    // Situação do documento no OCR (`ocr_situacao`). null conta como
    // pendente; `enviado` é o "aguardando retorno" do workflow; `erro` é o
    // com_erro das contagens. Independente do `status` do binário.
    const OCR_PENDENTE = 'pendente';
    const OCR_ENVIADO = 'enviado';
    const OCR_PROCESSADO = 'processado';
    const OCR_ERRO = 'erro';

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
        'ocr_situacao',
        'ocr_job_id',
        'ocr_job_id_cancelar',
        'ocr_enviado_em',
        'ocr_concluido_em',
        'ocr_erro',
    ];

    // Sem cast em data_juntada: mudaria a serialização de /documento/visualizar.
    protected $casts = [
        'id_documento' => 'integer',
        'downloaded_at' => 'datetime',
        'ocr_enviado_em' => 'datetime',
        'ocr_concluido_em' => 'datetime',
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
        // Estado interno do OCR: /documento/visualizar e
        // /processo/documentos/listar não ganham campo novo (CT-06).
        'ocr_situacao',
        'ocr_job_id',
        'ocr_job_id_cancelar',
        'ocr_enviado_em',
        'ocr_concluido_em',
        'ocr_erro',
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
