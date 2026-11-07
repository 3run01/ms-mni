<?php

namespace App\Http\Requests\Api;

use App\Rules\CallbackUrl;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Corpo de `POST /api/processo/ocr` (`SolicitacaoDeOcr`, CT-01; RF-03).
 *
 * Os 6 campos são obrigatórios. O par do PJe vem só do corpo: a rota fica
 * fora de InjectCredenciaisPjePadrao, e faltar `login_pje` ou `senha_pje` é
 * 422, mesmo com o par padrão configurado (RF-02). O `required` do Laravel
 * já recusa o valor só com espaços.
 *
 * `callback_url` passa pela regra CallbackUrl, a mesma das outras rotas com
 * callback (hosts liberados fora de `production`, RF-17), e tem até 2.048
 * caracteres (D-05). As mensagens de validação usam só o nome do campo,
 * nunca o valor: o 422 não devolve o par nem o `callback_token` (RNF-01).
 */
class SolicitarOcrProcessoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // autenticação é feita pelo middleware ValidateApiToken
    }

    public function rules(): array
    {
        return [
            'numero_processo' => ['required', 'string', 'regex:/^\d{20}$/'],
            'tribunal_id' => ['required', 'integer', 'min:1', Rule::exists('tribunais', 'id')->where('ativo', true)],
            'login_pje' => ['required', 'string'],
            'senha_pje' => ['required', 'string'],
            'callback_url' => ['required', 'string', 'max:2048', new CallbackUrl],
            'callback_token' => ['required', 'string', 'min:1', 'max:255'],
        ];
    }

    /**
     * O número com máscara é aceito e normalizado antes da regex (D-06). Só
     * texto é normalizado: outro tipo segue cru e cai no `string`.
     */
    public function prepareForValidation(): void
    {
        $numero = $this->input('numero_processo');

        if (is_string($numero)) {
            $this->merge(['numero_processo' => cleanNumeroProcesso($numero)]);
        }
    }
}
