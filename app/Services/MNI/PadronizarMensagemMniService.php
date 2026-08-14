<?php

namespace App\Services\MNI;

/**
 * Padroniza as mensagens cruas devolvidas pelos webservices MNI/PJe.
 *
 * Cada tribunal reporta o erro do seu jeito e quase sempre em linguagem de
 * máquina — "Erro ao realizar login via MNI. exception invoking: loginFailed"
 * é literalmente o que o PJe devolve. Esse texto vazava inteiro para quem
 * consome a API, para o payload do webhook de monitoramento e para a
 * dashboard. Aqui ele vira uma mensagem estável e acionável.
 *
 * A tradução é aplicada no construtor da MNIException, então vale para tudo
 * que lê getError(): respostas da API, erro_resumo das execuções de
 * monitoramento, webhooks e logs.
 *
 * O casamento é por trecho e sem diferenciar maiúsculas; vence a primeira
 * regra que bater. Mensagem sem regra correspondente passa intacta —
 * padronizar é substituir o que a gente reconhece, nunca esconder o que não
 * reconhece.
 *
 * Para padronizar uma nova mensagem, basta acrescentar uma regra em REGRAS.
 */
class PadronizarMensagemMniService
{
    /**
     * Regras de tradução, aplicadas na ordem.
     *
     * `trechos` precisa estar em minúsculas: a comparação normaliza a caixa da
     * mensagem recebida, não a do trecho.
     *
     * @var array<int, array{trechos: array<int, string>, mensagem: string}>
     */
    private const REGRAS = [
        [
            'trechos' => [
                'erro ao realizar login via mni',
                'loginfailed',
            ],
            'mensagem' => 'Erro ao realizar login via MNI. Verifique suas credenciais.',
        ],
    ];

    /**
     * Idempotente: normalizar uma mensagem já padronizada devolve ela mesma.
     */
    public static function normalizar(?string $mensagem): string
    {
        $mensagem = trim((string) $mensagem);

        if ($mensagem === '') {
            return '';
        }

        $comparavel = mb_strtolower($mensagem);

        foreach (self::REGRAS as $regra) {
            foreach ($regra['trechos'] as $trecho) {
                if (str_contains($comparavel, $trecho)) {
                    return $regra['mensagem'];
                }
            }
        }

        return $mensagem;
    }
}
