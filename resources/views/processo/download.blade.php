@php
    use App\Models\Processo;
    use App\Models\ProcessoParte;
    $nivelSigilo = Processo::niveisSigilo()[$processo->nivel_sigilo] ?? 'Não informado';
    $modalidadesPolo = ProcessoParte::modalidadePolo();
@endphp

@extends('layouts.relatorio.relatorio')
@section('content')
    <style>
        .capa-processo {
            font-family: 'Arial', sans-serif;
            color: #333;
            line-height: 1.6;
        }
        .titulo-principal {
            text-align: center;
            font-size: 24px;
            font-weight: bold;
            color: #1a1a1a;
            margin: 30px 0;
            padding: 15px;
            border-bottom: 3px solid #2c5aa0;
        }
        .secao-titulo {
            font-size: 18px;
            font-weight: bold;
            color: #2c5aa0;
            margin: 25px 0 15px 0;
            padding: 10px;
            background-color: #f5f5f5;
            border-left: 4px solid #2c5aa0;
        }
        .tabela-detalhes {
            width: 100%;
            border-collapse: collapse;
            margin: 15px 0;
        }
        .tabela-detalhes tr {
            border-bottom: 1px solid #e0e0e0;
        }
        .tabela-detalhes td {
            padding: 12px 15px;
            vertical-align: top;
        }
        .tabela-detalhes td:first-child {
            width: 35%;
            font-weight: bold;
            color: #555;
            background-color: #fafafa;
        }
        .tabela-detalhes td:last-child {
            width: 65%;
            color: #333;
        }
        .lista-partes {
            margin: 15px 0;
        }
        .parte-item {
            padding: 12px 15px;
            margin: 8px 0;
            background-color: #f9f9f9;
            border-left: 3px solid #2c5aa0;
            border-radius: 3px;
        }
        .parte-nome {
            font-weight: bold;
            color: #333;
        }
        .parte-polo {
            color: #666;
            font-style: italic;
            margin-left: 8px;
        }
        .numero-processo-destaque {
            font-size: 16px;
            color: #2c5aa0;
            font-weight: bold;
        }
    </style>

    <div class="capa-processo">
        <div class="titulo-principal">
            PROCESSO JUDICIAL
        </div>

        <div class="secao-titulo">Detalhes do Processo</div>
        <table class="tabela-detalhes">
            <tbody>
                <tr>
                    <td>Número do Processo:</td>
                    <td class="numero-processo-destaque">{{ $processo->numero_processo }}</td>
                </tr>
                <tr>
                    <td>Classe:</td>
                    <td>{{ $processo->classe?->descricao ?? 'Não informado' }}</td>
                </tr>
                <tr>
                    <td>Assuntos:</td>
                    <td>
                        @foreach ($processo->assuntos as $key => $assunto)
                            {{ $assunto->nome }}{{ $key < count($processo->assuntos) - 1 ? ', ' : '' }}
                        @endforeach
                    </td>
                </tr>
                <tr>
                    <td>Órgão Julgador:</td>
                    <td>{{ $processo->nome_orgao_julgador }}</td>
                </tr>
                <tr>
                    <td>Valor da Causa:</td>
                    <td>{{ $processo->valor_causa ? 'R$ ' . number_format($processo->valor_causa, 2, ',', '.') : 'Não informado' }}</td>
                </tr>
                <tr>
                    <td>Nível de Sigilo:</td>
                    <td>{{ $nivelSigilo }}</td>
                </tr>
                <tr>
                    <td>Justiça Gratuita:</td>
                    <td>{{ $processo->justica_gratuita ? 'Sim' : 'Não' }}</td>
                </tr>
            </tbody>
        </table>

        <div class="secao-titulo">Partes do Processo</div>
        <div class="lista-partes">
            @foreach ($processo->partes as $parte)
                <div class="parte-item">
                    <span class="parte-nome">{{ $parte->nome }}</span>
                    <span class="parte-polo">({{ $modalidadesPolo[$parte->polo] }})</span>
                </div>
            @endforeach
        </div>
    </div>
@endsection
