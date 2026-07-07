# Templates de E-mail - SIM (Sistema Integrado do MP-AP)

## Como criar novos e-mails

Todos os e-mails do sistema devem usar o componente padrão `x-mail::message` que inclui automaticamente:
- Logo do SIM no cabeçalho
- Layout responsivo
- Estilos padronizados
- Footer com informações da aplicação

### Exemplo básico

```blade
<x-mail::message>

# Título do E-mail

Corpo do e-mail com texto normal.

## Subtítulo

Mais conteúdo aqui...

Atenciosamente,<br>
{{ config('app.name') }}
</x-mail::message>
```

### Componentes disponíveis

#### 1. Botão
```blade
<x-mail::button :url="$url" color="primary">
Texto do Botão
</x-mail::button>
```

Cores disponíveis: `primary`, `success`, `error`

#### 2. Painel
```blade
<x-mail::panel>
Conteúdo destacado em um painel
</x-mail::panel>
```

#### 3. Tabela
```blade
<x-mail::table>
| Coluna 1 | Coluna 2 |
|----------|----------|
| Valor 1  | Valor 2  |
</x-mail::table>
```

### Personalização

Os templates de e-mail estão localizados em:
- **HTML**: `resources/views/vendor/mail/html/`
- **CSS**: `resources/views/vendor/mail/html/themes/default.css`
- **Header**: `resources/views/vendor/mail/html/header.blade.php`

A logo do SIM está configurada no header e será exibida automaticamente em todos os e-mails.

### Enviando e-mails com anexos

```php
Mail::to($destinatario)
    ->send(new NomeDoMailable($dados));
```

No Mailable:
```php
public function build()
{
    return $this->markdown('mail.pasta.template')
                ->attach($caminhoArquivo, [
                    'as' => 'nome-arquivo.pdf',
                    'mime' => 'application/pdf',
                ]);
}
```

## Estrutura de pastas

```
resources/views/mail/
├── README.md (este arquivo)
├── proceso/
│   └── autos.blade.php (exemplo de e-mail de processo)
└── [outras pastas organizadas por contexto]
```

## Boas práticas

1. Sempre use `x-mail::message` como componente base
2. Organize os templates em pastas por contexto (proceso, usuario, notificacao, etc)
3. Use markdown para formatação simples e legível
4. Inclua sempre uma assinatura com `{{ config('app.name') }}`
5. Teste os e-mails em diferentes clientes (Gmail, Outlook, etc)
