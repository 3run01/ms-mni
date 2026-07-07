<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CriarExportacaoProcessoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // autenticação é feita pelo middleware ValidateApiToken
    }

    public function rules(): array
    {
        return [
            'numero_processo' => ['required', 'string', 'max:25'],
            'tribunal_id' => ['nullable', 'integer'],
            'user_id' => ['required', 'integer', 'min:1'],
            'titulo' => ['required', 'string', 'max:255'],
            'formato' => ['required', 'string', Rule::in(['pdf'])],
            'ids_selecionados' => ['nullable', 'array'],
            'ids_selecionados.*' => ['integer'],
            'periodo_inicial' => ['nullable', 'date_format:Y-m-d', 'required_with:periodo_final'],
            'periodo_final' => ['nullable', 'date_format:Y-m-d', 'required_with:periodo_inicial'],
            'id_inicial' => ['nullable', 'integer', 'required_with:id_final'],
            'id_final' => ['nullable', 'integer', 'required_with:id_inicial'],
        ];
    }

    public function prepareForValidation(): void
    {
        if ($this->has('numero_processo')) {
            $this->merge(['numero_processo' => cleanNumeroProcesso($this->input('numero_processo'))]);
        }
    }
}
