<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProcessoParteRepresentante extends Model
{
    protected $table = 'processo_parte_representante';
    protected $fillable = [
        'processo_id',
        'parte_id',
        'nome',
        'numero_documento_principal',
        'inscricao',
        'tipo_representante',
    ];

    public function parte()
    {
        return $this->belongsTo(ProcessoParte::class, 'parte_id');
    }

    public function processo()
    {
        return $this->belongsTo(Processo::class, 'processo_id');
    }

    public static function tipoRepresentante()
    {
        return [
            "A" => "Advogado",
            "E" => "Escritório de Advocacia",
            "M" => "Ministério público",
            "D" => "Defensoria Pública",
            "P" => "Outro orgãos"
        ];
    }
}
