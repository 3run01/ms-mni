<?php

use App\Models\Processo;
use App\Models\Tribunal;
use App\Models\User;
use App\Models\ProcessoDocumento;
use App\Models\ProcessoMovimento;
use App\Models\ProcessoParte;
use App\Models\ProcessoParteRepresentante;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\MultiConnectionDatabaseTestCase;

uses(MultiConnectionDatabaseTestCase::class);

function loginProcessos(): User
{
    return User::factory()->make(['id' => 1]);
}

function novoProcesso(array $overrides = []): Processo
{
    return Processo::factory()->create($overrides);
}

it('redireciona visitante para o login na listagem', function () {
    $this->get('/processos')->assertRedirect('/login');
});

it('lista processos paginados no componente processos/index', function () {
    $prefixo = 'T1LISTA' . getmypid();
    novoProcesso(['numero_processo' => $prefixo . '001']);

    $this->actingAs(loginProcessos())
        ->get('/processos?busca=' . $prefixo)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('processos/index')
            ->has('processos.data', 1)
            ->where('processos.data.0.numero_processo', $prefixo . '001')
            ->has('processos.data.0.id')
            ->has('processos.total')
            ->has('processos.links'));
});

it('pagina de 20 em 20 preservando a query string', function () {
    $prefixo = 'T1PAG' . getmypid();
    $createdNumbers = [];
    for ($i = 1; $i <= 25; $i++) {
        $numero = $prefixo . str_pad((string) $i, 3, '0', STR_PAD_LEFT);
        novoProcesso(['numero_processo' => $numero]);
        $createdNumbers[] = $numero;
    }

    $this->actingAs(loginProcessos())
        ->get('/processos?busca=' . $prefixo)
        ->assertInertia(fn (Assert $page) => $page
            ->has('processos.data', 20)
            ->where('processos.total', 25)
            ->where('processos.current_page', 1));

    $this->actingAs(loginProcessos())
        ->get('/processos?busca=' . $prefixo . '&page=2')
        ->assertInertia(fn (Assert $page) => $page
            ->has('processos.data', 5)
            ->where('processos.current_page', 2));

    // Verify pagination stability: fetch both pages and check they don't overlap
    $page1Processos = Processo::where('numero_processo', 'ilike', "%{$prefixo}%")
        ->orderByDesc('created_at')
        ->orderByDesc('id')
        ->limit(20)
        ->get()
        ->pluck('numero_processo')
        ->toArray();

    $page2Processos = Processo::where('numero_processo', 'ilike', "%{$prefixo}%")
        ->orderByDesc('created_at')
        ->orderByDesc('id')
        ->offset(20)
        ->limit(5)
        ->get()
        ->pluck('numero_processo')
        ->toArray();

    // Assert disjoint sets: no numero_processo appears in both pages
    $overlap = array_intersect($page1Processos, $page2Processos);
    expect($overlap)->toBeEmpty('Pages must have disjoint sets of numero_processo');

    // Assert together they cover all 25 created numbers
    $allNumeros = array_merge($page1Processos, $page2Processos);
    expect(count($allNumeros))->toBe(25, 'Total of 25 unique numbers across pages');
});

it('filtra por tribunal_id', function () {
    $prefixo = 'T2TRIB' . getmypid();
    $tribunal = Tribunal::factory()->create(['nome' => 'Tribunal Filtro Processos']);
    novoProcesso(['numero_processo' => $prefixo . 'A', 'tribunal_id' => $tribunal->id]);
    novoProcesso(['numero_processo' => $prefixo . 'B', 'tribunal_id' => 999998]);

    $this->actingAs(loginProcessos())
        ->get("/processos?busca={$prefixo}&tribunal_id={$tribunal->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->has('processos.data', 1)
            ->where('processos.data.0.numero_processo', $prefixo . 'A')
            ->where('processos.data.0.tribunal', 'Tribunal Filtro Processos'));
});

it('filtra por status', function () {
    $prefixo = 'T2STAT' . getmypid();
    novoProcesso(['numero_processo' => $prefixo . 'A', 'status' => Processo::STATUS_PENDENTE_ENVIO]);
    novoProcesso(['numero_processo' => $prefixo . 'B', 'status' => Processo::STATUS_PETICIONADO]);

    $this->actingAs(loginProcessos())
        ->get('/processos?busca=' . $prefixo . '&status=' . urlencode(Processo::STATUS_PENDENTE_ENVIO))
        ->assertInertia(fn (Assert $page) => $page
            ->has('processos.data', 1)
            ->where('processos.data.0.status', Processo::STATUS_PENDENTE_ENVIO));
});

it('rejeita status fora do enum', function () {
    $this->actingAs(loginProcessos())
        ->from('/processos')
        ->get('/processos?status=Inventado')
        ->assertRedirect('/processos')
        ->assertSessionHasErrors('status');
});

it('filtra por intervalo de datas de criacao', function () {
    $prefixo = 'T2DATA' . getmypid();
    $antigo = novoProcesso(['numero_processo' => $prefixo . 'A']);
    $antigo->created_at = '2020-01-10 12:00:00';
    $antigo->save();
    $recente = novoProcesso(['numero_processo' => $prefixo . 'B']);
    $recente->created_at = '2020-03-10 12:00:00';
    $recente->save();

    $this->actingAs(loginProcessos())
        ->get("/processos?busca={$prefixo}&data_inicio=2020-03-01&data_fim=2020-03-31")
        ->assertInertia(fn (Assert $page) => $page
            ->has('processos.data', 1)
            ->where('processos.data.0.numero_processo', $prefixo . 'B'));
});

it('rejeita data_fim anterior a data_inicio', function () {
    $this->actingAs(loginProcessos())
        ->from('/processos')
        ->get('/processos?data_inicio=2026-02-01&data_fim=2026-01-01')
        ->assertRedirect('/processos')
        ->assertSessionHasErrors('data_fim');
});

it('filtra por classe_codigo', function () {
    $prefixo = 'T2CLAS' . getmypid();
    novoProcesso(['numero_processo' => $prefixo . 'A', 'classe_codigo' => '99991']);
    novoProcesso(['numero_processo' => $prefixo . 'B', 'classe_codigo' => '99992']);

    $this->actingAs(loginProcessos())
        ->get("/processos?busca={$prefixo}&classe_codigo=99991")
        ->assertInertia(fn (Assert $page) => $page
            ->has('processos.data', 1)
            ->where('processos.data.0.numero_processo', $prefixo . 'A'));
});

it('filtra por orgao julgador com busca parcial case-insensitive', function () {
    $prefixo = 'T2ORG' . getmypid();
    novoProcesso(['numero_processo' => $prefixo . 'A', 'nome_orgao_julgador' => 'Vara Única de Testolândia']);
    novoProcesso(['numero_processo' => $prefixo . 'B', 'nome_orgao_julgador' => 'Outra Vara']);

    $this->actingAs(loginProcessos())
        ->get("/processos?busca={$prefixo}&orgao_julgador=testol")
        ->assertInertia(fn (Assert $page) => $page
            ->has('processos.data', 1)
            ->where('processos.data.0.numero_processo', $prefixo . 'A'));
});

it('filtra por nivel de sigilo', function () {
    $prefixo = 'T2SIG' . getmypid();
    novoProcesso(['numero_processo' => $prefixo . 'A', 'nivel_sigilo' => '5']);
    novoProcesso(['numero_processo' => $prefixo . 'B', 'nivel_sigilo' => '0']);

    $this->actingAs(loginProcessos())
        ->get("/processos?busca={$prefixo}&nivel_sigilo=5")
        ->assertInertia(fn (Assert $page) => $page
            ->has('processos.data', 1)
            ->where('processos.data.0.numero_processo', $prefixo . 'A'));
});

it('combina multiplos filtros', function () {
    $prefixo = 'T2COMB' . getmypid();
    novoProcesso(['numero_processo' => $prefixo . 'A', 'status' => Processo::STATUS_ARQUIVADO, 'nivel_sigilo' => '2']);
    novoProcesso(['numero_processo' => $prefixo . 'B', 'status' => Processo::STATUS_ARQUIVADO, 'nivel_sigilo' => '0']);
    novoProcesso(['numero_processo' => $prefixo . 'C', 'status' => Processo::STATUS_PETICIONADO, 'nivel_sigilo' => '2']);

    $this->actingAs(loginProcessos())
        ->get('/processos?busca=' . $prefixo . '&status=' . urlencode(Processo::STATUS_ARQUIVADO) . '&nivel_sigilo=2')
        ->assertInertia(fn (Assert $page) => $page
            ->has('processos.data', 1)
            ->where('processos.data.0.numero_processo', $prefixo . 'A'));
});

it('entrega opcoes de filtro como props', function () {
    $this->actingAs(loginProcessos())
        ->get('/processos')
        ->assertInertia(fn (Assert $page) => $page
            ->has('tribunais')
            ->has('classes')
            ->has('statusOptions', 4)
            ->has('niveisSigilo'));
});

it('nao quebra a listagem quando ha classe_codigo nao numerico', function () {
    $prefixo = 'T2NONNUM' . getmypid();
    novoProcesso(['numero_processo' => $prefixo . 'A', 'classe_codigo' => 'ABC123']);

    $this->actingAs(loginProcessos())
        ->get('/processos?busca=' . $prefixo)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('processos.data', 1)
            ->has('classes'));
});

it('redireciona visitante para o login no detalhe', function () {
    $processo = novoProcesso();

    $this->get("/processos/{$processo->id}")->assertRedirect('/login');
});

it('retorna 404 para processo inexistente', function () {
    $this->actingAs(loginProcessos())
        ->get('/processos/999999999')
        ->assertNotFound();
});

it('mostra dados gerais, partes com representantes e assuntos', function () {
    $tribunal = Tribunal::factory()->create(['nome' => 'Tribunal Detalhe']);
    $processo = novoProcesso([
        'tribunal_id' => $tribunal->id,
        'nome_orgao_julgador' => 'Vara do Detalhe',
        'nivel_sigilo' => '1',
    ]);
    $processo->assuntos()->create(['nome' => 'Assunto Teste', 'assunto_codigo' => '123', 'principal' => true]);
    $processo->prioridades()->create(['descricao' => 'Idoso']);
    $parte = $processo->partes()->create(['nome' => 'Fulano de Tal', 'cpf_cnpj' => '12345678901', 'polo' => 'AT', 'municipio' => 'Macapá', 'estado' => 'AP']);
    ProcessoParteRepresentante::create([
        'processo_id' => $processo->id,
        'parte_id' => $parte->id,
        'nome' => 'Dra. Advogada',
        'tipo_representante' => 'A',
    ]);

    $this->actingAs(loginProcessos())
        ->get("/processos/{$processo->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('processos/show')
            ->where('processo.id', $processo->id)
            ->where('processo.tribunal', 'Tribunal Detalhe')
            ->where('processo.orgao_julgador', 'Vara do Detalhe')
            ->where('processo.nivel_sigilo', '1 - Segredo de Justiça')
            ->where('processo.assuntos.0.nome', 'Assunto Teste')
            ->where('processo.prioridades.0', 'Idoso')
            ->where('processo.partes.0.nome', 'Fulano de Tal')
            ->where('processo.partes.0.polo', 'Ativo')
            ->where('processo.partes.0.representantes.0.nome', 'Dra. Advogada')
            ->where('processo.partes.0.representantes.0.tipo', 'Advogado')
            ->missing('processo.payload_envio'));
});

it('adia movimentos e documentos e entrega no partial reload sem conteudo pesado', function () {
    $processo = novoProcesso();
    ProcessoMovimento::create([
        'processo_id' => $processo->id,
        'identificador_movimento' => 'MOV-1',
        'codigo_nacional' => 26,
        'complemento' => 'Distribuição',
        'data_hora' => '2026-01-05 10:00:00',
    ]);
    ProcessoDocumento::create([
        'processo_id' => $processo->id,
        'id_documento' => 'DOC-1',
        'tipo_documento' => 57,
        'descricao' => 'Petição Inicial',
        'mimetype' => 'application/pdf',
        'data_hora' => '2026-01-05 10:00:00',
        'status' => 'baixado',
        'file_size' => 2048,
    ]);

    // primeiro carregamento: deferred props ausentes
    $this->actingAs(loginProcessos())
        ->get("/processos/{$processo->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->component('processos/show')
            ->missing('movimentos')
            ->missing('documentos'));

    // partial reload (como o Inertia faz no cliente) entrega os dados
    $this->actingAs(loginProcessos())
        ->get("/processos/{$processo->id}", [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => \Inertia\Inertia::getVersion() ?? '',
            'X-Inertia-Partial-Component' => 'processos/show',
            'X-Inertia-Partial-Data' => 'movimentos,documentos',
        ])
        ->assertOk()
        ->assertJsonPath('props.movimentos.0.complemento', 'Distribuição')
        ->assertJsonPath('props.documentos.0.descricao', 'Petição Inicial')
        ->assertJsonMissingPath('props.documentos.0.conteudo_html')
        ->assertJsonMissingPath('props.documentos.0.path');
});
