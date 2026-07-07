# Menu de Navegação - SIM-MNI

Este documento descreve o menu de navegação implementado no sistema SIM-MNI.

## Visão Geral

O menu de navegação foi implementado com as seguintes funcionalidades:

-   Menu responsivo (desktop e mobile)
-   Dropdown para ferramentas de monitoramento
-   Links que abrem em nova aba
-   Design moderno com Tailwind CSS e Alpine.js

## Estrutura do Menu

### Desktop

-   **Dashboard**: Link direto para a página principal
-   **Monitoramento**: Dropdown com:
    -   Laravel Pulse (abre em nova aba)
    -   Log Viewer (abre em nova aba)
-   **Usuário**: Nome do usuário logado
-   **Sair**: Botão de logout

### Mobile

-   **Menu Hambúrguer**: Botão para abrir/fechar menu mobile
-   **Dashboard**: Link direto
-   **Monitoramento**: Seção com links para:
    -   Laravel Pulse (abre em nova aba)
    -   Log Viewer (abre em nova aba)
-   **Perfil do Usuário**: Avatar e informações
-   **Sair**: Botão de logout

## Funcionalidades Implementadas

### 1. Dropdown Interativo

-   Usa Alpine.js para interatividade
-   Abre/fecha ao clicar
-   Fecha automaticamente ao clicar fora
-   Transições suaves

### 2. Links em Nova Aba

-   Todos os links de monitoramento usam `target="_blank"`
-   Permite manter o sistema principal aberto
-   Facilita o trabalho com múltiplas ferramentas

### 3. Design Responsivo

-   Menu desktop para telas grandes
-   Menu mobile para dispositivos pequenos
-   Transições e animações suaves

### 4. Ícones Visuais

-   Ícones SVG para cada ferramenta
-   Ícone de dropdown (seta)
-   Avatar do usuário no mobile

## Tecnologias Utilizadas

### Frontend

-   **Tailwind CSS**: Estilização e layout responsivo
-   **Alpine.js**: Interatividade e gerenciamento de estado
-   **SVG Icons**: Ícones vetoriais para melhor qualidade

### Backend

-   **Laravel Blade**: Templates e componentes
-   **Laravel Auth**: Sistema de autenticação
-   **Middleware**: Proteção de rotas

## Credenciais de Teste

Para testar o sistema, use as seguintes credenciais:

```
Email: admin@admin.com
Senha: mpap2025
```

## URLs Disponíveis

### Sistema Principal

-   **Login**: `localhost:8006/login`
-   **Dashboard**: `localhost:8006/dashboard`

### Ferramentas de Monitoramento

-   **Laravel Pulse**: `localhost:8006/pulse/`
-   **Log Viewer**: `localhost:8006/log-viewer/`

## Como Usar

### 1. Fazer Login

1. Acesse `localhost:8006/login`
2. Use as credenciais de teste
3. Clique em "Entrar"

### 2. Acessar Ferramentas de Monitoramento

1. No menu, clique em "Monitoramento"
2. Selecione a ferramenta desejada:
    - **Laravel Pulse**: Para métricas em tempo real
    - **Log Viewer**: Para análise de logs
3. A ferramenta abrirá em uma nova aba

### 3. Navegação Mobile

1. Em dispositivos móveis, clique no ícone de menu (☰)
2. O menu mobile será exibido
3. Navegue pelas opções disponíveis

## Personalização

### Adicionar Novas Ferramentas

Para adicionar uma nova ferramenta ao menu:

1. **Desktop**: Adicione um novo link no dropdown:

```html
<a
    href="/nova-ferramenta/"
    target="_blank"
    class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 flex items-center"
>
    <svg
        class="mr-2 h-4 w-4"
        fill="none"
        stroke="currentColor"
        viewBox="0 0 24 24"
    >
        <!-- Ícone SVG -->
    </svg>
    Nova Ferramenta
</a>
```

2. **Mobile**: Adicione no menu mobile:

```html
<a
    href="/nova-ferramenta/"
    target="_blank"
    class="block py-2 text-sm text-gray-600 hover:text-gray-800 hover:bg-gray-50 rounded-md px-2"
>
    Nova Ferramenta
</a>
```

### Modificar Estilos

-   Edite as classes Tailwind CSS no arquivo `resources/views/layouts/app.blade.php`
-   Use o sistema de cores do Tailwind para personalização
-   Mantenha a consistência com o design atual

## Troubleshooting

### Problema: Menu não funciona

-   Verifique se o Alpine.js está carregado
-   Verifique o console do navegador para erros JavaScript
-   Confirme que o Vite está compilado corretamente

### Problema: Links não abrem em nova aba

-   Verifique se `target="_blank"` está presente nos links
-   Confirme que as rotas estão configuradas corretamente
-   Verifique se o middleware de autenticação está funcionando

### Problema: Menu mobile não aparece

-   Verifique se as classes `sm:hidden` estão corretas
-   Confirme que o Alpine.js está funcionando
-   Teste em diferentes tamanhos de tela

## Segurança

-   Todas as ferramentas de monitoramento requerem autenticação
-   Middleware `AuthorizePulse` protege as rotas
-   Logs de auditoria para todos os acessos
-   Possibilidade de restrição por permissões de usuário
