<?php

namespace App\Http\Requests;

use App\Models\Tribunal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TribunalRequest extends FormRequest
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
        $criando = $this->isMethod('POST');

        return [
            'nome' => ['required', 'string', 'max:255'],
            'tipo' => ['nullable', 'string', Rule::in(Tribunal::getTipos())],
            'login' => ['required', 'string', 'max:255'],
            'password' => [$criando ? 'required' : 'nullable', 'string'],
            'url_webservice_mni' => ['required', 'url', 'max:255'],
            'url_webservice_mni_complementar' => ['required', 'url', 'max:255'],
            'url_webservice_mni_consultar_processo' => ['nullable', 'url', 'max:255'],
            'url_consulta_pje' => ['nullable', 'url', 'max:255'],
            'url_webservice_mni_criminal' => ['nullable', 'url', 'max:255'],
            'url_recuperar_senha_tribunal' => ['nullable', 'url', 'max:255'],
            'codigo_peticao_inicial' => ['nullable', 'string', 'max:255'],
            'codigo_peticao_avulsa' => ['nullable', 'string', 'max:255'],
            'codigo_certidao_inicio_fim' => ['nullable', 'string', 'max:255'],
            'codigo_seeu' => ['nullable', 'string', 'max:255'],
            'usar_codigo_documento_padrao' => ['nullable', 'string', 'max:255'],
            'versao_mni' => ['nullable', 'string', 'max:50'],
            'ativo' => ['required', 'boolean'],
            'enviar_dados_criminais' => ['boolean'],
            'usar_credencial_tribunal' => ['boolean'],
        ];
    }
}
