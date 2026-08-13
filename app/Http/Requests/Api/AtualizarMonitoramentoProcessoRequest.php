<?php

namespace App\Http\Requests\Api;

use App\Models\ProcessoMonitoramento;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AtualizarMonitoramentoProcessoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // autenticação é feita pelo middleware ValidateApiToken
    }

    public function rules(): array
    {
        return [
            'intervalo_horas' => [
                'sometimes',
                'integer',
                'min:' . config('pje.monitoramento.intervalo_min_horas'),
                'max:' . config('pje.monitoramento.intervalo_max_horas'),
            ],
            'callback_url' => ['sometimes', 'string', 'max:2048', new \App\Rules\CallbackUrl],
            'callback_token' => ['sometimes', 'string', 'max:500'],
            // cancelar é o DELETE; aqui só pausa/retoma
            'status' => ['sometimes', Rule::in([
                ProcessoMonitoramento::STATUS_ATIVO,
                ProcessoMonitoramento::STATUS_PAUSADO,
            ])],
        ];
    }
}
