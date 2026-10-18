{{--
    Primary navigation, NPM-style horizontal tabs. Each entry lists the route-name patterns that
    mark it active, so sub-pages (create/edit) keep their section highlighted.
--}}
@php
    $items = [
        ['label' => 'Dashboard', 'icon' => 'dashboard', 'route' => 'dashboard', 'active' => ['dashboard']],
        ['label' => 'Proxy Hosts', 'icon' => 'globe', 'route' => 'proxy-hosts.index', 'active' => ['proxy-hosts.*']],
        ['label' => 'Certificados SSL', 'icon' => 'shield', 'route' => 'certificates.index', 'active' => ['certificates.*']],
        ['label' => 'Usuários', 'icon' => 'users', 'route' => 'users.index', 'active' => ['users.*']],
        ['label' => 'Acesso por IP', 'icon' => 'lock', 'route' => 'ip-allowlist.index', 'active' => ['ip-allowlist.*']],
    ];
@endphp

<nav class="app-nav" aria-label="Navegação principal">
    <div class="app-nav-inner">
        @foreach ($items as $item)
            <a href="{{ route($item['route']) }}"
               @class(['nav-link', 'is-active' => request()->routeIs(...$item['active'])])
               wire:navigate>
                <x-icon :name="$item['icon']" class="icon-sm" />
                {{ $item['label'] }}
            </a>
        @endforeach
    </div>
</nav>
