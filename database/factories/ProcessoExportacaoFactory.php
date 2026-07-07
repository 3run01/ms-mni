<?php

namespace Database\Factories;

use App\Models\ProcessoExportacao;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ProcessoExportacaoFactory extends Factory
{
    protected $model = ProcessoExportacao::class;

    public function definition(): array
    {
        return [
            'user_id' => 1,
            'numero_processo' => '6001255-81.2024.8.03.0003',
            'tribunal_id' => null,
            'titulo' => 'Processo 6001255-81.2024.8.03.0003 — PDF',
            'formato' => ProcessoExportacao::FORMATO_PDF,
            'status' => ProcessoExportacao::STATUS_ENFILEIRADO,
            'uuid_arquivo' => null,
            's3_path' => null,
            'tamanho_bytes' => null,
            'erro_resumo' => null,
            'filtros' => ['ids_selecionados' => [1, 2, 3]],
            'webhook_enviado_em' => null,
            'webhook_tentativas' => 0,
        ];
    }

    public function processando(): self
    {
        return $this->state(fn () => [
            'status' => ProcessoExportacao::STATUS_PROCESSANDO,
            'uuid_arquivo' => (string) Str::uuid(),
        ]);
    }

    public function concluido(): self
    {
        $uuid = (string) Str::uuid();
        return $this
            ->state(fn () => [
                'status' => ProcessoExportacao::STATUS_CONCLUIDO,
                'uuid_arquivo' => $uuid,
                'tamanho_bytes' => 1024 * 1024,
            ])
            ->afterMaking(function (ProcessoExportacao $exportacao) {
                $exportacao->s3_path = "downloads/{$exportacao->user_id}/{$exportacao->uuid_arquivo}.pdf";
            })
            ->afterCreating(function (ProcessoExportacao $exportacao) {
                $exportacao->s3_path = "downloads/{$exportacao->user_id}/{$exportacao->uuid_arquivo}.pdf";
                $exportacao->save();
            });
    }

    public function falhou(string $erro = 'Documentos do processo indisponíveis no momento.'): self
    {
        return $this->state(fn () => [
            'status' => ProcessoExportacao::STATUS_FALHOU,
            'erro_resumo' => $erro,
        ]);
    }

    public function webhookEnviado(): self
    {
        return $this->state(fn () => [
            'webhook_enviado_em' => now(),
            'webhook_tentativas' => 1,
        ]);
    }
}
