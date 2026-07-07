<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProcessoPrioridade extends Model
{
    protected $table = 'processo_prioridades';
    protected $fillable = [
        'processo_id',
        'descricao',
    ];
}
