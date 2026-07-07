<?php

namespace App\Models;

// use App\Traits\LogsActivityOptionsTrait;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProcessoParte extends Model
{
    use HasFactory;

    protected $logName = 'ProcessoParte';
    protected $table = 'processo_partes';
    protected $fillable = [
        'processo_id',
        'nome',
        'cpf_cnpj',
        'polo',
        'cep',
        'logradouro',
        'numero',
        'bairro',
        'municipio',
        'estado',
    ];

    public static function modalidadePolo()
    {
        return [
            "AT" => "Ativo",
            "PA" => "Passivo",
            "TC" => "Terceiro",
            "FL" => "Fiscal da lei diverso",
            "TJ" => "Testemunha do juízo",
            "AD" => "Assistente simples desinteressado",
            "VI" => "Vítima",
        ];
    }

    public function representantesProcessual()
    {
        return $this->hasMany(ProcessoParteRepresentante::class, 'parte_id');
    }
}
