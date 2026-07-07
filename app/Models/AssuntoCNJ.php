<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class AssuntoCNJ extends Model
{
    use HasFactory;

    protected $logName = 'AssuntoCNJ';
    protected $table = 'cnj.assuntos';
    protected $primaryKey = 'codigo';
    protected $fillable = [
        'codigo',
        'descricao',
        'codigo_pai',
        'tem_filhos',
        'situacao'
    ];

    public static function getAll()
    {
        $assuntos = self::select(['id', DB::raw("CONCAT(descricao, ' (', codigo, ')') as label"), 'codigo', 'codigo_pai as codigo_pai'])
            //            ->withCount('filhos')
            ->where('situacao', 'A')
            ->get()
            ->toArray();

        return $assuntos;
    }

    public static function tree($data, $parentId = null)
    {
        $tree = [];
        $disabled = [];

        foreach ($data as $item) {
            if ($item['codigo_pai'] == $parentId) {
                // Recursivamente constrói a árvore para os filhos
                $childrenResult = self::tree($data, $item['codigo']);
                $children = $childrenResult['tree'];

                if (!empty($children)) {
                    // Se o item tiver filhos, armazena-os na árvore e adiciona o ID ao array de desabilitados
                    $item['children'] = $children;
                    $disabled[] = $item['id']; // Adiciona o ID ao array de desabilitados
                }

                $tree[] = $item;
                // Combina o array de IDs desabilitados dos filhos
                //                $disabled = array_merge($disabled, $childrenResult['disabled']);
            }
        }

        return ['tree' => $tree, 'disabled' => $disabled];
    }


    public function filhos()
    {
        return $this->hasMany(self::class, 'codigo_pai', 'codigo'); // Corrigido as chaves
    }
}
