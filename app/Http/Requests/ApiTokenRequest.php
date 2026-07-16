<?php
// app/Http/Requests/ApiTokenRequest.php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ApiTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('api_tokens', 'name')],
            'expires_at' => ['nullable', 'date', 'after:today'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Informe um nome para o token.',
            'name.unique' => 'Já existe um token com esse nome.',
            'name.max' => 'O nome pode ter no máximo 255 caracteres.',
            'expires_at.date' => 'Data de expiração inválida.',
            'expires_at.after' => 'A data de expiração deve ser futura.',
        ];
    }
}
