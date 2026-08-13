<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CriarMonitoramentoProcessoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // autenticação é feita pelo middleware ValidateApiToken
    }

    public function rules(): array
    {
        return [
            'numero_processo' => ['required', 'string', 'max:25'],
            'tribunal_id' => ['required', 'integer', Rule::exists('tribunais', 'id')->where('ativo', true)],
            'intervalo_horas' => [
                'required',
                'integer',
                'min:' . config('pje.monitoramento.intervalo_min_horas'),
                'max:' . config('pje.monitoramento.intervalo_max_horas'),
            ],
            'callback_url' => ['required', 'string', 'max:2048', new \App\Rules\CallbackUrl],
            'callback_token' => ['required', 'string', 'max:500'],
            'login_pje' => ['nullable', 'string', 'max:255', 'required_with:senha_pje'],
            'senha_pje' => ['nullable', 'string', 'max:255', 'required_with:login_pje'],
        ];
    }

    public function prepareForValidation(): void
    {
        if ($this->has('numero_processo')) {
            $this->merge(['numero_processo' => cleanNumeroProcesso($this->input('numero_processo'))]);
        }
    }
}
