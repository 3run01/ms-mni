<?php

namespace App\Http\Controllers;

use App\Models\Processo;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProcessoController extends Controller
{
    public function index(Request $request): Response
    {
        $filtros = $request->validate([
            'busca' => ['nullable', 'string', 'max:255'],
        ]);

        $processos = Processo::query()
            ->without(['prioridades', 'assuntos'])
            ->when($filtros['busca'] ?? null,
                fn ($q, $v) => $q->where('numero_processo', 'ilike', "%{$v}%"))
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
        ]);
    }
}
