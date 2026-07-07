<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProcessoAssunto extends Model
{
    use HasFactory;
    protected $table = 'processo_assuntos';
    protected $fillable = [
        'processo_id',
        'nome',
        'assunto_codigo',
        'principal',
    ];

    protected $hidden = [
        'id',
        'created_at',
        'updated_at',
        'deleted_at'
    ];

    public function processo()
    {
        return $this->belongsTo(Processo::class, 'processo_id');
    }

    // public function assunto()
    // {
    //     return $this->belongsTo(AssuntoCNMP::class, 'assunto_codigo', 'codigo');
    // }
}
