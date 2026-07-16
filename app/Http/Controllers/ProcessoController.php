<?php

namespace App\Http\Controllers;

use App\Models\ClasseCNJ;
use App\Models\Processo;
use App\Models\Tribunal;
use App\Models\ProcessoParte;
use App\Models\ProcessoParteRepresentante;
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

        // Mapa codigo->descricao das classes referenciadas por processos, reutilizado
        // para rotular as linhas E alimentar o filtro. CAST(codigo AS varchar): codigo é
        // integer em cnj.classes, classe_codigo é varchar (dado externo do MNI).
        $classes = ClasseCNJ::query()
            ->whereIn(DB::raw('CAST(codigo AS varchar)'), Processo::query()->select('classe_codigo')->whereNotNull('classe_codigo')->distinct())
            ->orderBy('descricao')
            ->get(['codigo', 'descricao']);
        $classesMap = $classes->keyBy(fn ($c) => (string) $c->codigo);

        $processos = Processo::query()
            ->without(['prioridades', 'assuntos', 'classe'])
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
                'classe' => $classesMap->get((string) $p->classe_codigo)?->descricao,
                'status' => $p->status,
                'valor_causa' => $p->valor_causa,
                'created_at' => $p->created_at,
            ]);

        return Inertia::render('processos/index', [
            'processos' => $processos,
            'filtros' => $filtros,
            'tribunais' => Tribunal::query()->select(['id', 'nome'])->orderBy('nome')->get(),
            'classes' => $classes->map(fn ($c) => ['codigo' => $c->codigo, 'descricao' => $c->descricao]),
            'statusOptions' => [
                Processo::STATUS_PENDENTE_ENVIO,
                Processo::STATUS_PROCESSANDO_ENVIO,
                Processo::STATUS_PETICIONADO,
                Processo::STATUS_ARQUIVADO,
            ],
            'niveisSigilo' => Processo::niveisSigilo(),
        ]);
    }

    public function show(string $processo): Response
    {
        // Resolve manualmente sem o eager-load default de `classe` ($with do model):
        // a relação classe (classe_codigo varchar -> cnj.classes.codigo integer) estoura
        // 22P02 se classe_codigo for não-numérico. Mesma proteção do index.
        $processo = Processo::without(['classe'])
            ->with(['partes.representantesProcessual'])
            ->findOrFail($processo);

        $classeDescricao = ClasseCNJ::query()
            ->where(DB::raw('CAST(codigo AS varchar)'), $processo->classe_codigo)
            ->value('descricao');

        $polos = ProcessoParte::modalidadePolo();
        $tiposRepresentante = ProcessoParteRepresentante::tipoRepresentante();
        $niveisSigilo = Processo::niveisSigilo();

        return Inertia::render('processos/show', [
            'processo' => [
                'id' => $processo->id,
                'numero_processo' => $processo->numero_processo,
                'status' => $processo->status,
                'tribunal' => $processo->tribunal?->nome,
                'classe' => $classeDescricao,
                'orgao_julgador' => $processo->nome_orgao_julgador,
                'instancia_orgao_julgador' => $processo->instancia_orgao_julgador,
                'valor_causa' => $processo->valor_causa,
                'nivel_sigilo' => $niveisSigilo[(int) $processo->nivel_sigilo] ?? $processo->nivel_sigilo,
                'justica_gratuita' => $processo->justica_gratuita,
                'pedido_liminar' => $processo->pedido_liminar,
                'motivo_segredo_justica' => $processo->motivo_segredo_justica,
                'created_at' => $processo->created_at,
                'assuntos' => $processo->assuntos->map(fn ($a) => [
                    'nome' => $a->nome,
                    'assunto_codigo' => $a->assunto_codigo,
                    'principal' => (bool) $a->principal,
                ]),
                'prioridades' => $processo->prioridades->pluck('descricao'),
                'partes' => $processo->partes->map(fn ($parte) => [
                    'id' => $parte->id,
                    'polo' => $polos[$parte->polo] ?? $parte->polo,
                    'nome' => $parte->nome,
                    'cpf_cnpj' => $parte->cpf_cnpj,
                    'endereco' => collect([
                        $parte->logradouro,
                        $parte->numero,
                        $parte->bairro,
                        $parte->municipio,
                        $parte->estado,
                        $parte->cep,
                    ])->filter()->implode(', '),
                    'representantes' => $parte->representantesProcessual->map(fn ($r) => [
                        'id' => $r->id,
                        'nome' => $r->nome,
                        'numero_documento_principal' => $r->numero_documento_principal,
                        'inscricao' => $r->inscricao,
                        'tipo' => $tiposRepresentante[$r->tipo_representante] ?? $r->tipo_representante,
                    ]),
                ]),
            ],
            // deferred: processos antigos têm centenas de movimentos/documentos;
            // o primeiro paint não espera por eles
            'movimentos' => Inertia::defer(fn () => $processo->movimentos()
                ->get(['id', 'codigo_nacional', 'complemento', 'data_hora', 'id_documento_vinculado'])
                ->map(fn ($m) => [
                    'id' => $m->id,
                    'codigo_nacional' => $m->codigo_nacional,
                    'complemento' => $m->complemento,
                    'data_hora' => $m->data_hora,
                    'tem_documento' => filled($m->id_documento_vinculado),
                ])),
            'documentos' => Inertia::defer(fn () => $processo->documentos()
                ->get(['id', 'descricao', 'tipo_documento', 'mimetype', 'file_size', 'nivel_sigilo', 'data_juntada', 'data_hora', 'status'])
                ->map(fn ($d) => [
                    'id' => $d->id,
                    'descricao' => $d->descricao,
                    'tipo_documento' => $d->tipo_documento,
                    'mimetype' => $d->mimetype,
                    'file_size' => $d->file_size,
                    'nivel_sigilo' => $d->nivel_sigilo,
                    'data_juntada' => $d->data_juntada,
                    'data_hora' => $d->data_hora,
                    'status' => $d->status,
                ])),
        ]);
    }
}
