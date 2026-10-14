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
    const TIPO_TJ = 'Tribunal de Justiça';

    // formato derivado do número CNJ, comparado pelo cliente da API (DEP-02)
    const REGEX_SIGLA = '/^(STF|STJ|STM|TST|TSE|TJDFT|TRF[1-6]|TRT([1-9]|1[0-9]|2[0-4])|TRE-[A-Z]{2}|TJ[A-Z]{2}|TJM[A-Z]{2})$/';

    // várias linhas ativas podem ter a mesma sigla; o grau as distingue
    const GRAUS = ['1', '2'];

    protected $logName = 'Tribunal';
    protected $table = 'tribunais';
    protected $fillable = [
        'nome',
        'sigla',
        'grau',
        'url_webservice_mni',
        'url_webservice_mni_consultar_processo',
        'url_webservice_mni_complementar',
        'url_consulta_pje',
        'url_webservice_mni_criminal',
        'url_recuperar_senha_tribunal',
        'tipo',
        'ativo',
        'codigo_peticao_inicial',
        'codigo_peticao_avulsa',
        'codigo_certidao_inicio_fim',
        'codigo_seeu',
        'usar_codigo_documento_padrao',
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
            static $temUuid = null;
            $temUuid ??= $model->getConnection()
                ->getSchemaBuilder()
                ->hasColumn($model->getTable(), 'uuid');

            if ($temUuid) {
                $model->uuid = Str::uuid();
            }
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
            self::TIPO_TSE,
            self::TIPO_TJ,
        ];
    }
    public function tiposDocumentos()
    {
        return $this->hasMany(TipoDocumento::class, 'tribunal_id', 'id');
    }
}
