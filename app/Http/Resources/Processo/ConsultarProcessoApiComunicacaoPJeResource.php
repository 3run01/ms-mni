<?php

namespace App\Http\Resources\Processo;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ConsultarProcessoApiComunicacaoPJeResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        dd($request);
        return [
            'numero_processo' => $this->numeroProcesso ?? null,
            'tribunal_sigla' => $this->siglaTribunal ?? null,
            'classe_codigo' => $this->codigoClasse ?? null,
            'classe_codigo' => $this->codigoClasse ?? null,
            'nome_classe' => $this->nomeClasse ?? null,
            // 'partes' => $this->getPartes(),
            // 'pagina' => $this->pagina,
        ];
    }

    private function getPartes(): array
    {
        return collect($this->destinatarios)->map(function ($parte) {
            return [
                'nome' => $parte['nome'] ?? null,
                'polo' => $this->getModalidadePolo($parte['polo']) ?? null,
            ];
        })->toArray();
    }

    private static function getModalidadePolo(): array
    {
        return [
            "A" => "Ativo",
            "P" => "Passivo",
        ];
    }
}
