# Layout do painel (control-plane)

Este documento explica **por que** o painel tem a cara e a estrutura que tem, e **onde mexer**
para mudar cada coisa. Se for alterar o visual, comece por aqui.

## Restrições que moldaram as decisões

| Restrição | Consequência no layout |
|---|---|
| O control-plane roda em rede privada, sem saída garantida para a internet (SPEC.md §6.0). | Nada de CDN: sem Google Fonts, sem Tailwind via CDN, sem icon font. Fonte do sistema, ícones SVG inline, CSS local. |
| A imagem do control-plane não tem Node nem build de assets. | CSS puro num único arquivo (`public/css/panel.css`), servido como arquivo estático. Sem Tailwind/Vite no runtime. |
| Interface sofisticada (SPA) não é objetivo da v1 (SPEC.md §3). | Livewire + Blade com `wire:navigate` (navegação sem recarregar a página) e Alpine só para abas. Nenhum framework JS adicional. |
| Operadores vêm do Nginx Proxy Manager (NPM). | Navegação no estilo do NPM: abas horizontais no topo, Dashboard com contadores, listagens em card com tabela, formulário de proxy host com abas **Detalhes / SSL**. |

## Mapa de arquivos

```
apps/control-plane/
├── public/css/panel.css                         ← TODO o visual (tokens, shell, componentes)
└── resources/views/
    ├── layouts/
    │   ├── app.blade.php                        ← shell logado: cabeçalho + navegação + conteúdo
    │   ├── guest.blade.php                      ← login / 2FA / definir senha
    │   └── partials/
    │       ├── head.blade.php                   ← <head> comum (título, tema, CSS)
    │       ├── theme.blade.php                  ← script de tema claro/escuro (inline, antes da pintura)
    │       ├── theme-toggle.blade.php           ← botão sol/lua
    │       └── nav.blade.php                    ← itens do menu (lista única)
    ├── components/
    │   ├── icon.blade.php                       ← <x-icon name="..."/> (SVGs Lucide)
    │   └── certificate-status.blade.php         ← status do certificado → rótulo + cor
    ├── partials/pagination.blade.php            ← paginação (as views padrão do Laravel exigem Tailwind)
    └── livewire/…                               ← páginas; só usam classes do panel.css
```

## Como mudar…

### …as cores, raios, sombras ou a largura do conteúdo
Edite **somente** a seção 1 (tema claro) e a seção 2 (tema escuro) de `public/css/panel.css`.
Nenhuma outra regra do arquivo usa cor literal: tudo passa por `var(--color-…)`. Trocar
`--color-accent` muda botões, aba ativa, foco e links de uma vez.

Regra: o tema escuro só sobrescreve **cores e sombras**. Espaçamento e tamanhos nunca mudam
entre temas, para que um ajuste de layout não precise ser feito duas vezes.

### …o tema claro/escuro
- `layouts/partials/theme.blade.php` define `data-theme="light|dark"` no `<html>` **antes da
  primeira pintura** (por isso é inline no `<head>` e não um arquivo .js — senão quem usa tema escuro
  vê um flash branco a cada carregamento).
- Ordem de preferência: escolha salva pelo botão (`localStorage['apy-theme']`) → preferência do
  sistema operacional. Se o `localStorage` estiver bloqueado, o tema do sistema é usado.
- Para adicionar um terceiro tema, crie um bloco `:root[data-theme="nome"] { … }` com os mesmos
  tokens da seção 2 e ajuste `toggleTheme()`.

### …o menu
`layouts/partials/nav.blade.php` tem um array com rótulo, ícone, rota e os padrões de rota que
marcam o item como ativo (`proxy-hosts.*` mantém "Proxy Hosts" ativo em criar/editar). Adicionar um
item é adicionar uma linha nesse array.

### …ou adicionar um ícone
Copie os elementos internos do SVG em <https://lucide.dev> para o array de
`components/icon.blade.php`. Use com `<x-icon name="nome" />` (`class="icon-sm"` para 15px).

### …uma página nova
Siga o esqueleto das páginas existentes — é o que mantém o painel consistente:

```blade
<div>
    <div class="page-header">
        <div>
            <h1 class="page-title">Título</h1>
            <p class="page-subtitle">Uma frase dizendo para que serve a página.</p>
        </div>
        <a href="…" class="btn" wire:navigate><x-icon name="plus" class="icon-sm" /> Ação principal</a>
    </div>

    <div class="card">
        <div class="card-header"><h2 class="card-title">…</h2></div>
        <div class="table-wrap"><table class="table">…</table></div>
        {{ $paginator->links('partials.pagination') }}
    </div>
</div>
```

Formulários: `<form class="card form-card">` com `card-body` (campos `field` / `label` / `input`)
e `card-footer` (Cancelar à esquerda, ação primária à direita). Booleanos usam `.switch`; escolhas
exclusivas com explicação usam `.choice` (ex.: aba SSL do proxy host).

Evite `style="…"` nas views: se precisar de um ajuste, crie uma classe na seção 5 do CSS.

## Componentes disponíveis (seção 5 do CSS)

| Classe | Uso |
|---|---|
| `.card`, `.card-header`, `.card-body`, `.card-footer` | Contêiner padrão de qualquer bloco |
| `.table`, `.cell-primary`, `.cell-secondary`, `.cell-actions` | Listagens (linha principal + linha secundária menor) |
| `.btn`, `.btn-secondary`, `.btn-danger`, `.btn-sm`, `.icon-btn` | Botões; ações de linha usam `.icon-btn` |
| `.badge`, `.badge-success/warning/danger/accent`, `.badge-plain` | Status com bolinha colorida (`-plain` sem bolinha) |
| `.alert`, `.alert-success`, `.alert-danger` | Mensagens dentro da página |
| `.tabs`, `.tab.is-active` | Abas (controladas por Alpine `x-data`) |
| `.switch`, `.choice` | Checkbox estilizado / opção em cartão |
| `.stat-grid`, `.stat` | Contadores do Dashboard |
| `.empty-state` | Lista vazia |

## Certificados no formulário de proxy host

A aba **SSL** oferece as mesmas três opções do NPM (`App\Enums\ProxyHostSslOption`):

1. **Sem SSL**: só HTTP.
2. **Certificado existente**: um certificado enviado em *Certificados SSL* (ex.: wildcard) ou
   emitido antes.
3. **Solicitar novo (Let's Encrypt)**: o proxy host é salvo primeiro e a emissão é disparada logo
   em seguida (`RequestCertificateIssuanceAction`). A ordem é obrigatória: o desafio HTTP-01 precisa
   do bloco `:80` do domínio já publicado nas réplicas. Quando o certificado fica válido, ele é
   anexado ao proxy host automaticamente (SPEC.md §11). O proxy host precisa estar habilitado e o
   DNS do domínio precisa apontar para o gateway.
