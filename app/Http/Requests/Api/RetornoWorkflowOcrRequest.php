<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Corpo do retorno do workflow "Jurídico OCR (Gemini)" no `webhook_url`
 * (CT-04; nós "Avisar o sistema" e "Avisar falha"). Só `job_id` e `status`
 * decidem a resposta: os demais campos (`message`, `error_detail`,
 * `indexed_chunks`...) são informativos e nunca levam a 422 (RF-12).
 */
class RetornoWorkflowOcrRequest extends FormRequest
{
    public const STATUS_SUCESSO = 'success';

    public const STATUS_ERRO = 'error';

    public function authorize(): bool
    {
        return true; // autenticação é feita pelo middleware ValidarAssinaturaWebhookOcr
    }

    public function rules(): array
    {
        return [
            'job_id' => ['required', 'string', 'max:64'],
            'status' => ['required', 'in:'.self::STATUS_SUCESSO.','.self::STATUS_ERRO],
        ];
    }

    public function jobId(): string
    {
        return (string) $this->validated('job_id');
    }

    public function sucesso(): bool
    {
        return $this->validated('status') === self::STATUS_SUCESSO;
    }

    /**
     * O detalhe do `error`, para o resumo em `ocr_erro`: o 1º texto entre
     * `error_detail`, `error` e `message`. Campo em outro formato é
     * ignorado, sem 422.
     */
    public function detalheDoErro(): ?string
    {
        foreach (['error_detail', 'error', 'message'] as $campo) {
            $valor = $this->input($campo);

            if (is_string($valor) && trim($valor) !== '') {
                return $valor;
            }
        }

        return null;
    }
}
