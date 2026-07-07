# Configuração do Opcodes Log Viewer

Este documento descreve a configuração e uso do Opcodes Log Viewer no sistema SIM-MNI.

## Visão Geral

O Opcodes Log Viewer é uma ferramenta web para visualização e análise de logs do Laravel. Ele fornece uma interface intuitiva para navegar, filtrar e buscar em logs do sistema.

## Acesso

- **URL**: `/log-viewer/`
- **Autenticação**: Obrigatória (usuários autenticados)
- **Permissões**: Todos os usuários autenticados (configurável)

## Funcionalidades

### 1. Visualização de Logs
- Lista todos os arquivos de log disponíveis
- Visualização em tempo real
- Navegação por páginas
- Destaque de sintaxe para diferentes níveis de log

### 2. Filtros e Busca
- Filtro por nível de log (ERROR, WARNING, INFO, DEBUG)
- Busca por texto em logs
- Filtro por data/hora
- Filtro por contexto específico

### 3. Análise de Logs
- Contadores de logs por nível
- Estatísticas de erros
- Rastreamento de stack traces
- Visualização de contexto de exceções

## Configuração

### Arquivos de Log
- **Localização**: `storage/logs/`
- **Arquivo principal**: `laravel.log`
- **Formato**: Logs do Laravel/Monolog

### Configurações Personalizadas

```php
// config/log-viewer.php

// URL de retorno ao sistema
'back_to_system_url' => config('app.url') . '/dashboard',
'back_to_system_label' => 'Voltar ao Dashboard',

// Timezone
'timezone' => 'America/Sao_Paulo',

// Formato de data
'datetime_format' => 'd/m/Y H:i:s',

// Middleware de autenticação
'middleware' => [
    'web',
    'pulse.auth',
],
```

## Níveis de Log

O sistema suporta os seguintes níveis de log:

- **EMERGENCY**: Sistema inutilizável
- **ALERT**: Ação deve ser tomada imediatamente
- **CRITICAL**: Condições críticas
- **ERROR**: Condições de erro
- **WARNING**: Condições de aviso
- **NOTICE**: Condições normais mas significativas
- **INFO**: Mensagens informativas
- **DEBUG**: Mensagens de debug

## Uso

### 1. Acessar o Log Viewer
1. Faça login no sistema
2. Navegue para `/log-viewer/`
3. Selecione um arquivo de log

### 2. Filtrar Logs
1. Use os filtros na barra lateral
2. Selecione o nível de log desejado
3. Aplique filtros de data se necessário

### 3. Buscar em Logs
1. Use a caixa de busca
2. Digite o termo desejado
3. Os resultados serão destacados

### 4. Analisar Erros
1. Filtre por nível ERROR
2. Clique em um log para ver detalhes
3. Analise o stack trace se disponível

## Integração com Sistema de Logging

### Logs Personalizados

Para criar logs personalizados no sistema:

```php
use Illuminate\Support\Facades\Log;

// Log de informação
Log::info('Usuário acessou o sistema', ['user_id' => $user->id]);

// Log de erro
Log::error('Erro ao processar documento', [
    'document_id' => $document->id,
    'error' => $exception->getMessage()
]);

// Log de debug
Log::debug('Processando documento', ['document_id' => $document->id]);
```

### Contexto de Logs

Sempre inclua contexto relevante nos logs:

```php
Log::info('Documento processado com sucesso', [
    'document_id' => $document->id,
    'processo_id' => $document->processo_id,
    'tipo_documento' => $document->tipo_documento,
    'user_id' => auth()->id(),
    'processing_time' => $processingTime
]);
```

## Performance

### Otimizações
- Cache de índices de logs para navegação rápida
- Carregamento lazy de arquivos grandes
- Compressão de logs antigos

### Limitações
- Arquivos de log muito grandes podem impactar performance
- Recomenda-se rotação regular de logs
- Limite de 100MB por arquivo de log

## Manutenção

### Rotação de Logs
Configure a rotação automática de logs no Laravel:

```php
// config/logging.php
'daily' => [
    'driver' => 'daily',
    'path' => storage_path('logs/laravel.log'),
    'level' => env('LOG_LEVEL', 'debug'),
    'days' => 14, // Manter logs por 14 dias
],
```

### Limpeza de Logs Antigos
Execute periodicamente:

```bash
# Limpar logs antigos
php artisan log:clear

# Ou manualmente
rm storage/logs/laravel-*.log
```

## Segurança

- Acesso restrito a usuários autenticados
- Logs de auditoria para acessos
- Possibilidade de restrição por permissões
- Não exposição de informações sensíveis em logs

## Troubleshooting

### Problema: Logs não aparecem
- Verifique se os logs estão sendo gerados
- Verifique permissões de leitura em `storage/logs/`
- Verifique configuração de logging

### Problema: Interface lenta
- Verifique tamanho dos arquivos de log
- Configure rotação de logs
- Verifique cache do sistema

### Problema: Erro de permissão
- Verifique middleware de autenticação
- Verifique permissões do usuário
- Verifique configuração de rotas
