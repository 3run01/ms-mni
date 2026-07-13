<?php

namespace App\Http\Controllers;

use App\Models\ClasseCNJ;
use App\Models\Processo;
use App\Models\Tribunal;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ProcessoController extends Controller
{
    public function index(Request $request): Response
    {
        $filtros = $request->validate([
            'busca' => ['nullable', 'string', 'max:255'],
            'tribunal_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in([
                Processo::STATUS_PENDENTE_ENVIO,
                Processo::STATUS_PROCESSANDO_ENVIO,
                Processo::STATUS_PETICIONADO,
                Processo::STATUS_ARQUIVADO,
            ])],
            'data_inicio' => ['nullable', 'date'],
            'data_fim' => ['nullable', 'date', 'after_or_equal:data_inicio'],
            'classe_codigo' => ['nullable', 'string', 'max:255'],
            'orgao_julgador' => ['nullable', 'string', 'max:255'],
            'nivel_sigilo' => ['nullable', 'integer', 'between:0,5'],
        ]);

        $processos = Processo::query()
            ->without(['prioridades', 'assuntos'])
            ->when($filtros['busca'] ?? null,
                fn ($q, $v) => $q->where('numero_processo', 'ilike', "%{$v}%"))
            ->when($filtros['tribunal_id'] ?? null,
                fn ($q, $v) => $q->where('tribunal_id', $v))
            ->when($filtros['status'] ?? null,
                fn ($q, $v) => $q->where('status', $v))
            ->when($filtros['data_inicio'] ?? null,
                fn ($q, $v) => $q->whereDate('created_at', '>=', $v))
            ->when($filtros['data_fim'] ?? null,
                fn ($q, $v) => $q->whereDate('created_at', '<=', $v))
            ->when($filtros['classe_codigo'] ?? null,
                fn ($q, $v) => $q->where('classe_codigo', $v))
            ->when($filtros['orgao_julgador'] ?? null,
                fn ($q, $v) => $q->where('nome_orgao_julgador', 'ilike', "%{$v}%"))
            // nivel_sigilo é varchar no Postgres: comparar como string
            ->when(isset($filtros['nivel_sigilo']),
                fn ($q) => $q->where('nivel_sigilo', (string) $filtros['nivel_sigilo']))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Processo $p) => [
                'id' => $p->id,
                'numero_processo' => $p->numero_processo,
                'tribunal' => $p->tribunal?->nome,
                'classe' => $p->classe?->descricao,
                'status' => $p->status,
                'valor_causa' => $p->valor_causa,
                'created_at' => $p->created_at,
            ]);

        return Inertia::render('processos/index', [
            'processos' => $processos,
            'filtros' => $filtros,
            'tribunais' => Tribunal::query()->select(['id', 'nome'])->orderBy('nome')->get(),
            'classes' => ClasseCNJ::query()
                ->whereIn('codigo', Processo::query()->selectRaw('CAST(classe_codigo as integer)')->whereNotNull('classe_codigo')->distinct())
                ->orderBy('descricao')
                ->get(['codigo', 'descricao'])
                ->map(fn ($c) => ['codigo' => $c->codigo, 'descricao' => $c->descricao]),
            'statusOptions' => [
                Processo::STATUS_PENDENTE_ENVIO,
                Processo::STATUS_PROCESSANDO_ENVIO,
                Processo::STATUS_PETICIONADO,
                Processo::STATUS_ARQUIVADO,
            ],
            'niveisSigilo' => Processo::niveisSigilo(),
        ]);
    }
}
