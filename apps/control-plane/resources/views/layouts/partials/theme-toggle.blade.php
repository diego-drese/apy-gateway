{{-- Both icons are rendered; CSS shows the one matching the active theme (no JS re-render needed). --}}
<button type="button" class="icon-btn theme-toggle {{ $class ?? '' }}" onclick="toggleTheme()" title="Alternar tema claro/escuro" aria-label="Alternar tema claro/escuro">
    <x-icon name="moon" class="theme-icon-to-dark" />
    <x-icon name="sun" class="theme-icon-to-light" />
</button>
