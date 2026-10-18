{{--
    Livewire pagination styled with public/css/panel.css. Laravel's default pagination views
    assume Tailwind, which this panel doesn't ship (no build step), so they rendered unstyled.
    Usage: {{ $paginator->links('partials.pagination') }}
--}}
@if ($paginator->hasPages())
    <div class="pagination-wrap">
        <nav aria-label="Paginação">
            <p>{{ $paginator->firstItem() }}–{{ $paginator->lastItem() }} de {{ $paginator->total() }}</p>
            <div class="pagination-actions">
                <button type="button" class="btn btn-secondary btn-sm"
                        wire:click="previousPage('{{ $paginator->getPageName() }}')"
                        @disabled($paginator->onFirstPage())>Anterior</button>
                <button type="button" class="btn btn-secondary btn-sm"
                        wire:click="nextPage('{{ $paginator->getPageName() }}')"
                        @disabled(! $paginator->hasMorePages())>Próxima</button>
            </div>
        </nav>
    </div>
@endif
