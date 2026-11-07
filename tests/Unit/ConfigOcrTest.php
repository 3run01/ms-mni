<?php

use Symfony\Component\Finder\Finder;

/*
 * Configuração do OCR (`services.n8n_ocr`) e chaves do .env.example (T06).
 * Os prazos A, P, M e W vêm da SPEC (RF-06, RF-11, RF-13, RF-18) e têm
 * invariantes: A < P, A < M e W abaixo dos 60 s do cliente do Sistema.
 */

const CHAVES_ENV_OCR = [
    'N8N_OCR_URL',
    'N8N_OCR_TOKEN',
    'N8N_OCR_BASE_CONHECIMENTO',
    'N8N_OCR_WEBHOOK_BASE_URL',
    'CALLBACK_HOSTS_LIBERADOS',
];

/**
 * Lê `config/services.php` de novo com as N8N_OCR_* no valor pedido
 * (null = ausente), sem depender do .env da máquina.
 *
 * @param  array<string, string|null>  $valores
 */
function configOcrDoAmbiente(array $valores): array
{
    $chaves = array_unique(array_merge(array_keys($valores), ['N8N_OCR_URL', 'N8N_OCR_TOKEN', 'N8N_OCR_BASE_CONHECIMENTO', 'N8N_OCR_WEBHOOK_BASE_URL']));
    $antes = [];

    foreach ($chaves as $chave) {
        $antes[$chave] = [$_ENV[$chave] ?? null, $_SERVER[$chave] ?? null, getenv($chave)];
        unset($_ENV[$chave], $_SERVER[$chave]);
        putenv($chave);

        if (($valores[$chave] ?? null) !== null) {
            $_ENV[$chave] = $_SERVER[$chave] = $valores[$chave];
            putenv("{$chave}={$valores[$chave]}");
        }
    }

    try {
        return (require config_path('services.php'))['n8n_ocr'];
    } finally {
        foreach ($antes as $chave => [$env, $server, $getenv]) {
            unset($_ENV[$chave], $_SERVER[$chave]);
            putenv($chave);

            if ($env !== null) {
                $_ENV[$chave] = $env;
            }
            if ($server !== null) {
                $_SERVER[$chave] = $server;
            }
            if ($getenv !== false) {
                putenv("{$chave}={$getenv}");
            }
        }
    }
}

it('expõe as 10 chaves do bloco n8n_ocr', function () {
    expect(config('services.n8n_ocr'))->toBeArray()
        ->toHaveKeys([
            'url',
            'token',
            'base_conhecimento',
            'webhook_base_url',
            'prazo_sem_progresso_minutos',
            'idade_maxima_sem_retorno_minutos',
            'idade_maxima_absoluta_minutos',
            'espera_da_trava_segundos',
            'trava_validade_segundos',
            'timeout_http_segundos',
        ])
        ->toHaveCount(10);
});

it('usa os padrões da SPEC para os prazos', function () {
    expect(config('services.n8n_ocr.prazo_sem_progresso_minutos'))->toBe(25)
        ->and(config('services.n8n_ocr.idade_maxima_sem_retorno_minutos'))->toBe(20)
        ->and(config('services.n8n_ocr.idade_maxima_absoluta_minutos'))->toBe(60)
        ->and(config('services.n8n_ocr.espera_da_trava_segundos'))->toBe(45)
        ->and(config('services.n8n_ocr.trava_validade_segundos'))->toBe(600)
        ->and(config('services.n8n_ocr.timeout_http_segundos'))->toBe(30);
});

it('A é menor que P e menor que M na configuração padrão', function () {
    $a = config('services.n8n_ocr.idade_maxima_sem_retorno_minutos');
    $p = config('services.n8n_ocr.prazo_sem_progresso_minutos');
    $m = config('services.n8n_ocr.idade_maxima_absoluta_minutos');

    expect($a)->toBe(20)
        ->and($p)->toBe(25)
        ->and($m)->toBe(60)
        ->and($a)->toBeLessThan($p)
        ->and($a)->toBeLessThan($m);
});

it('W fica abaixo dos 60 s do cliente', function () {
    expect(config('services.n8n_ocr.espera_da_trava_segundos'))->toBe(45)
        ->toBeLessThan(60);
});

it('a trava vale mais que a espera por ela', function () {
    expect(config('services.n8n_ocr.trava_validade_segundos'))
        ->toBeGreaterThan(config('services.n8n_ocr.espera_da_trava_segundos'));
});

it('lê url, token e base_conhecimento das N8N_OCR_*, sem valor padrão para url e token', function () {
    $ausentes = configOcrDoAmbiente([]);

    expect($ausentes['url'])->toBeNull()
        ->and($ausentes['token'])->toBeNull()
        ->and($ausentes['base_conhecimento'])->toBe('');

    $definidas = configOcrDoAmbiente([
        'N8N_OCR_URL' => 'http://n8n:5678/webhook/juridico-ocr',
        'N8N_OCR_TOKEN' => 'token-ocr',
        'N8N_OCR_BASE_CONHECIMENTO' => 'base-processos',
        'N8N_OCR_WEBHOOK_BASE_URL' => 'http://host.docker.internal:8002',
    ]);

    expect($definidas['url'])->toBe('http://n8n:5678/webhook/juridico-ocr')
        ->and($definidas['token'])->toBe('token-ocr')
        ->and($definidas['base_conhecimento'])->toBe('base-processos')
        ->and($definidas['webhook_base_url'])->toBe('http://host.docker.internal:8002');
});

it('webhook_base_url cai no APP_URL quando N8N_OCR_WEBHOOK_BASE_URL está ausente ou vazia', function () {
    expect(configOcrDoAmbiente([])['webhook_base_url'])->toBe(env('APP_URL'))
        ->and(configOcrDoAmbiente(['N8N_OCR_WEBHOOK_BASE_URL' => ''])['webhook_base_url'])->toBe(env('APP_URL'));
});

it('o .env.example tem as chaves novas sem valor', function () {
    $linhas = preg_split('/\R/', file_get_contents(base_path('.env.example')));

    foreach (CHAVES_ENV_OCR as $chave) {
        expect(in_array("{$chave}=", $linhas, true))->toBeTrue("{$chave} sem valor no .env.example");
    }
});

it('o .env.example comenta CALLBACK_HOSTS_LIBERADOS', function () {
    $exemplo = file_get_contents(base_path('.env.example'));

    expect($exemplo)->toMatch('/#[^\n]*host\.docker\.internal[^\n]*\n(#[^\n]*\n)*CALLBACK_HOSTS_LIBERADOS=/')
        ->and($exemplo)->toMatch('/#[^\n]*production[^\n]*\n(#[^\n]*\n)*CALLBACK_HOSTS_LIBERADOS=/i');
});

it('nenhum env() fora de config/ lê as chaves novas', function () {
    $ocorrencias = [];

    $arquivos = Finder::create()->files()->name('*.php')
        ->in([base_path('app'), base_path('routes'), base_path('database'), base_path('bootstrap'), base_path('resources')])
        ->exclude(['cache']);

    foreach ($arquivos as $arquivo) {
        foreach (CHAVES_ENV_OCR as $chave) {
            if (preg_match('/env\(\s*[\'"]' . preg_quote($chave, '/') . '[\'"]/', $arquivo->getContents())) {
                $ocorrencias[] = "{$arquivo->getRelativePathname()}: {$chave}";
            }
        }
    }

    expect($ocorrencias)->toBe([]);
});
