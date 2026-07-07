<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ProcessoMovimento extends Model
{
    use HasFactory;

    protected $table = 'processo_movimentos';
    protected $fillable = [
        'processo_id',
        'identificador_movimento',
        'codigo_nacional',
        'complemento',
        'data_hora',
        'id_documento_vinculado',
    ];

    protected $hidden = [
        'processo_id',
        'id',
        'created_at',
        'updated_at',
        'deleted_at'
    ];
}
