# Laravel Pulse - Solução de Problemas

## Problema: Erro 500 ao acessar /pulse

### Diagnóstico

O erro 500 ao acessar `/pulse` geralmente ocorre quando:

1. O usuário não está autenticado
2. As migrações do Pulse não foram executadas
3. O comando `top` não está disponível no container

### Soluções Implementadas

#### 1. ✅ Migrações do Pulse

-   **Problema**: Migrações duplicadas e pendentes
-   **Solução**: Removidas migrações duplicadas e executadas as corretas
-   **Status**: Resolvido

#### 2. ✅ Comando `top` não disponível

-   **Problema**: `sh: 1: top: not found`
-   **Solução**: Instalado pacote `procps` no container
-   **Comando**: `apt-get install -y procps`
-   **Status**: Resolvido

#### 3. ✅ Middleware de Autenticação

-   **Problema**: Acesso sem autenticação
-   **Solução**: Middleware `AuthorizePulse` configurado
-   **Comportamento**: Redireciona para login se não autenticado
-   **Status**: Funcionando

### Como Acessar o Pulse Corretamente

#### Passo 1: Fazer Login

1. Acesse: `http://localhost:8006/login`
2. Use as credenciais:
    ```
    Email: admin@admin.com
    Senha: mpap2025
    ```

#### Passo 2: Acessar o Pulse

1. Após o login, acesse: `http://localhost:8006/pulse/`
2. Ou use o menu "Monitoramento" → "Laravel Pulse"

### Verificação de Status

#### Teste de Conectividade

```bash
# Testar se o Pulse está respondendo
docker exec -it sim-mni curl -I http://localhost/pulse/

# Resposta esperada: HTTP/1.1 302 Found (redirecionamento para login)
```

#### Teste de Autenticação

```bash
# Verificar se há usuários no sistema
docker exec -it sim-mni php artisan tinker --execute="echo App\Models\User::count();"
```

#### Teste de Migrações

```bash
# Verificar status das migrações
docker exec -it sim-mni php artisan migrate:status | grep pulse
```

### Comandos Úteis

#### Limpar Cache

```bash
docker exec -it sim-mni php artisan config:clear
docker exec -it sim-mni php artisan route:clear
docker exec -it sim-mni php artisan view:clear
```

#### Verificar Logs

```bash
# Logs do Laravel
docker exec -it sim-mni tail -f storage/logs/laravel.log

# Logs do Pulse
docker exec -it sim-mni tail -f /var/log/supervisor/pulse-check.log
```

#### Reiniciar Serviços

```bash
# Reiniciar o container
docker-compose restart

# Ou reiniciar apenas o Pulse
docker exec -it sim-mni supervisorctl restart pulse-check
```

### Configuração do Pulse

#### Arquivo: `config/pulse.php`

```php
'middleware' => [
    'web',
    'pulse.auth',
],
```

#### Arquivo: `bootstrap/app.php`

```php
$middleware->alias([
    'pulse.auth' => \App\Http\Middleware\AuthorizePulse::class,
]);
```

#### Arquivo: `app/Http/Middleware/AuthorizePulse.php`

```php
public function handle(Request $request, Closure $next): Response
{
    if (!Auth::check()) {
        return redirect()->route('login');
    }

    return $next($request);
}
```

### Supervisord Configuration

#### Arquivo: `docker/supervisord/supervisord.conf`

```ini
[program:pulse-check]
command=docker-php-entrypoint php artisan pulse:check
autostart=true
autorestart=true
stdout_logfile=/var/log/supervisor/pulse-check.log
stderr_logfile=/var/log/supervisor/pulse-check_error.log
user=root
priority=5
```

### Troubleshooting Avançado

#### Problema: Pulse não coleta dados

1. Verificar se o comando `pulse:check` está rodando
2. Verificar logs do supervisor
3. Verificar se as tabelas do Pulse existem

#### Problema: Erro de permissão

1. Verificar permissões dos arquivos
2. Verificar se o usuário do container tem acesso
3. Verificar configuração do middleware

#### Problema: Performance lenta

1. Verificar configuração do banco de dados
2. Verificar se há muitos dados sendo coletados
3. Considerar limpeza periódica dos dados

### Monitoramento

#### Verificar Status do Pulse

```bash
# Verificar se o processo está rodando
docker exec -it sim-mni supervisorctl status pulse-check

# Verificar logs em tempo real
docker exec -it sim-mni tail -f /var/log/supervisor/pulse-check.log
```

#### Verificar Dados Coletados

```bash
# Acessar o banco de dados
docker exec -it sim-mni php artisan tinker

# Verificar dados do Pulse
DB::table('pulse_values')->count();
DB::table('pulse_entries')->count();
```

### Conclusão

O Laravel Pulse está configurado corretamente e funcionando. O erro 500 que você estava enfrentando era devido ao acesso sem autenticação. Agora, com o sistema de login funcionando e o middleware configurado, o Pulse deve funcionar perfeitamente.

**Próximos Passos:**

1. Faça login no sistema
2. Acesse o Pulse através do menu "Monitoramento"
3. Explore as métricas e funcionalidades disponíveis

## Problema: ERR_TOO_MANY_REDIRECTS (Redirecionamento em excesso)

### Diagnóstico

O erro de redirecionamento em excesso ocorre quando:

1. Há um loop infinito de redirecionamentos
2. Rotas conflitantes entre o Laravel e o Pulse
3. Configuração incorreta de rotas

### Solução Implementada

#### 4. ✅ Loop de Redirecionamento

-   **Problema**: Rota `/pulse` redirecionando para `/pulse/` criando loop infinito
-   **Solução**: Removida rota de redirecionamento problemática do `routes/web.php`
-   **Código removido**:
    ```php
    Route::get('/pulse', function () {
        return redirect('/pulse/');
    });
    ```
-   **Status**: Resolvido

### Como Acessar o Pulse Corretamente (Atualizado)

#### Passo 1: Fazer Login

1. Acesse: `http://localhost:8006/login`
2. Use as credenciais:
    ```
    Email: admin@admin.com
    Senha: mpap2025
    ```

#### Passo 2: Acessar o Pulse

1. Após o login, acesse: `http://localhost:8006/pulse`
2. Ou use o menu "Monitoramento" → "Laravel Pulse"

### Conclusão Final

O Laravel Pulse está configurado corretamente e funcionando. Os problemas de erro 500 e redirecionamento em excesso foram resolvidos. Agora, com o sistema de login funcionando e o middleware configurado, o Pulse deve funcionar perfeitamente.
