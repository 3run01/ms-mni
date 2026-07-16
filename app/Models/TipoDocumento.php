<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Builder;

class TipoDocumento extends Model
{
    use HasFactory, SoftDeletes;
    protected $logName = 'TipoDocumento';
    protected $table = 'tipos_documentos';

    protected $fillable = [
        'tribunal_id',
        'descricao',
        'codigo',
        'exibir_peticao_incidental',
        'exibir_peticao_inicial',
        'exibir_expediente'
    ];

    // Flags de exibição são config local (não vêm do MNI); default false ao criar
    // via command/CRUD, satisfazendo as colunas NOT NULL de tipos_documentos.
    protected $attributes = [
        'exibir_peticao_incidental' => false,
        'exibir_peticao_inicial' => false,
        'exibir_expediente' => false,
    ];

    public function tribunal()
    {
        return $this->belongsTo(Tribunal::class)->where('ativo', true);
    }

    // Escopo local para filtrar documentos por tribunal ativo
    public function scopeAtivoTribunal(Builder $query)
    {
        return $query->whereHas('tribunal', function ($query) {
            $query->where('ativo', true);
        });
    }
}
