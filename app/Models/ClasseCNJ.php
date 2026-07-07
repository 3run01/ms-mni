<?php

namespace App\Models;

// use App\Traits\LogsActivityOptionsTrait;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ClasseCNJ extends Model
{
    use HasFactory;

    protected $logName = 'ClasseCNJ';
    protected $table = 'cnj.classes';
    protected $fillable = [
        'codigo',
        'descricao',
        'codigo_pai',
        'tem_filhos',
        'situacao'
    ];

    protected $hidden = [
        'id',
        'created_at',
        'updated_at',
        'deleted_at',
        'tem_filhos',
    ];

    // Relacionamento de pai para filhos
    public function filhos()
    {
        return $this->hasMany(ClasseCNJ::class, 'codigo_pai', 'codigo');
    }

    // Relacionamento de filho para pai
    public function pai()
    {
        return $this->belongsTo(ClasseCNJ::class, 'codigo_pai', 'codigo');
    }
}
