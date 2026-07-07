<?php

namespace App\Models;

// use App\Traits\LogsActivityOptionsTrait;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Tribunal extends Model
{
    use HasFactory, SoftDeletes;

    const TIPO_STF = 'Supremo Tribunal Federal (STF)';
    const TIPO_STJ = 'Superior Tribunal de Justiça (STJ)';
    const TIPO_STM = 'Superior Tribunal Militar (STM)';
    const TIPO_TST = 'Tribunal Superior do Trabalho (TST)';
    const TIPO_TSE = 'Tribunal Superior Eleitoral (TSE)';

    protected $connection = 'sim';
    protected $logName = 'Tribunal';
    protected $table = 'tribunais';
    protected $fillable = [
        'nome',
        'codigo_tribunal',
        'segmento_justica',
        'login',
        'password',
        'url_webservice_mni',
        'url_webservice_mni_consultar_processo',
        'url_webservice_mni_complementar',
        'url_consulta_pje',
        'url_webservice_mni_criminal',
        'tipo',
        'ativo',
        'url_recuperar_senha_tribunal',
        'codigo_peticao_inicial',
        'codigo_peticao_avulsa',
        'codigo_certidao_inicio_fim',
        'enviar_dados_criminais',
        'versao_mni',
    ];

    protected $hidden = [
        // 'id',
        'login',
        'password',
        'url_webservice_mni',
        'url_webservice_mni_complementar',
        'url_consulta_pje',
        'url_recuperar_senha_tribunal',
        'created_at',
        'updated_at',
        'deleted_at'
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            $model->uuid = Str::uuid();
        });
    }

    // public function jurisdicoes()
    // {
    //     return $this->hasMany(Jurisdicao::class, 'tribunal_id', 'id');
    // }

    public static function getTipos()
    {
        return [
            self::TIPO_STF,
            self::TIPO_STJ,
            self::TIPO_STM,
            self::TIPO_TST,
            self::TIPO_TSE
        ];
    }
    // public function tiposDocumentos()
    // {
    //     return $this->hasMany(TipoDocumento::class, 'tribunal_id', 'id');
    // }
}
