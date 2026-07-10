<?php

namespace App\Http\Controllers;

use App\Http\Requests\TribunalRequest;
use App\Models\Tribunal;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class TribunalController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('tribunais/index', [
            'tribunais' => Tribunal::query()
                ->select(['id', 'nome', 'tipo', 'versao_mni', 'ativo'])
                ->orderBy('nome')
                ->get(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('tribunais/create', [
            'tipos' => Tribunal::getTipos(),
        ]);
    }

    public function store(TribunalRequest $request): RedirectResponse
    {
        Tribunal::create($request->validated());

        return redirect()->route('tribunais.index')->with('success', 'Tribunal criado.');
    }

    public function edit(Tribunal $tribunal): Response
    {
        return Inertia::render('tribunais/edit', [
            'tipos' => Tribunal::getTipos(),
            'tribunal' => $tribunal->only([
                'id',
                'nome',
                'tipo',
                'versao_mni',
                'ativo',
                'url_webservice_mni',
                'url_webservice_mni_consultar_processo',
                'url_webservice_mni_complementar',
                'url_consulta_pje',
                'url_webservice_mni_criminal',
                'url_recuperar_senha_tribunal',
                'codigo_peticao_inicial',
                'codigo_peticao_avulsa',
                'codigo_certidao_inicio_fim',
                'codigo_seeu',
                'usar_codigo_documento_padrao',
                'enviar_dados_criminais',
            ]),
        ]);
    }

    public function update(TribunalRequest $request, Tribunal $tribunal): RedirectResponse
    {
        $tribunal->update($request->validated());

        return redirect()->route('tribunais.index')->with('success', 'Tribunal atualizado.');
    }

    public function toggleAtivo(Tribunal $tribunal): RedirectResponse
    {
        $tribunal->update(['ativo' => ! $tribunal->ativo]);

        return back()->with('success', 'Tribunal atualizado.');
    }
}
