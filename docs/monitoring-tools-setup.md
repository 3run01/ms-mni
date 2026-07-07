# Configuração de Ferramentas de Monitoramento

Este documento descreve como configurar e usar as ferramentas de monitoramento do sistema SIM-MNI.

## Ferramentas Disponíveis

### 1. Laravel Pulse
- **URL**: `/pulse/`
- **Descrição**: Dashboard de monitoramento em tempo real
- **Funcionalidades**: Métricas de performance, exceções, filas, consultas lentas

### 2. Opcodes Log Viewer
- **URL**: `/log-viewer/`
- **Descrição**: Visualizador de logs do sistema
- **Funcionalidades**: Visualização, filtros e busca em logs

## Autenticação

Ambas as ferramentas são protegidas por autenticação web. Apenas usuários autenticados podem acessar.

### Middleware de Autorização

O middleware `AuthorizePulse` é responsável por:
- Verificar se o usuário está autenticado
- Registrar logs de acesso para auditoria
- Redirecionar para login se não autenticado

### Configuração de Permissões

Por padrão, todos os usuários autenticados podem acessar as ferramentas. Para restringir apenas a administradores:

1. Adicione um campo `is_admin` na tabela `users`
2. Descomente a verificação no middleware `AuthorizePulse`:

```php
if (!Auth::user()->is_admin) {
    abort(403, 'Acesso negado. Apenas administradores podem acessar esta ferramenta.');
}
```

## Configuração do Banco de Dados

### Laravel Pulse
- **Conexão**: `pulse`
- **Schema**: `pulse`
- **Tabelas**: `pulse_values`, `pulse_entries`, `pulse_aggregates`

### Log Viewer
- **Logs**: Armazenados em `storage/logs/`
- **Configuração**: `config/log-viewer.php`

## Variáveis de Ambiente

```env
# Laravel Pulse
PULSE_DB_CONNECTION=pulse
PULSE_ENABLED=true
PULSE_PATH=pulse

# Log Viewer
LOG_VIEWER_ENABLED=true
LOG_VIEWER_API_ONLY=false
```

## Logs de Auditoria

Todos os acessos às ferramentas de monitoramento são registrados nos logs do sistema com as seguintes informações:
- ID do usuário
- Email do usuário
- IP de origem
- User Agent
- URL acessada
- Timestamp

## Troubleshooting

### Problema: Erro 403 - Acesso Negado
- Verifique se o usuário está autenticado
- Verifique as permissões do usuário (se configurado)

### Problema: Erro 500 - Erro Interno
- Verifique se as migrações do Pulse foram executadas
- Verifique se a conexão de banco 'pulse' está configurada
- Verifique os logs do sistema

### Problema: Dashboard não carrega
- Verifique se o middleware está registrado corretamente
- Verifique se as rotas estão configuradas
- Verifique se os assets foram publicados

## Segurança

- As ferramentas são acessíveis apenas via HTTPS em produção
- Todos os acessos são logados para auditoria
- Middleware de autenticação obrigatório
- Possibilidade de restrição por permissões de usuário
