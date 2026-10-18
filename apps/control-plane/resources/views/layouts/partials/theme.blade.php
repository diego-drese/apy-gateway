{{--
    Must stay inline in <head>: it sets data-theme before the first paint, otherwise dark-mode
    users see a white flash on every full page load. Preference order: the explicit choice saved
    by the toggle → the operating system's preference. localStorage can throw (private windows,
    blocked site data), in which case the OS preference is used and the toggle still works for the
    current page.
--}}
<script>
    (function () {
        var storageKey = 'apy-theme';

        function storedTheme() {
            try { return localStorage.getItem(storageKey); } catch (e) { return null; }
        }

        function preferredTheme() {
            var stored = storedTheme();
            if (stored === 'light' || stored === 'dark') return stored;
            return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
        }

        function applyTheme(theme) {
            document.documentElement.setAttribute('data-theme', theme);
        }

        window.toggleTheme = function () {
            var next = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
            applyTheme(next);
            try { localStorage.setItem(storageKey, next); } catch (e) { /* preference just isn't persisted */ }
        };

        applyTheme(preferredTheme());
        // wire:navigate swaps the page without a full reload; re-apply in case the <html>
        // attributes were replaced along with it.
        document.addEventListener('livewire:navigated', function () { applyTheme(preferredTheme()); });
    })();
</script>
