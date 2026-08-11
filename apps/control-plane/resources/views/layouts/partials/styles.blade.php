<style>
    /* Deliberately minimal — SPEC.md §3: "Interface de usuário sofisticada (SPA)" is explicitly
       not a v1 goal. Plain embedded CSS, no build step, no CDN (the control-plane runs on a
       private network with no guaranteed internet egress — SPEC.md §6.0). */
    body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Helvetica, Arial, sans-serif; margin: 0; background: #f5f5f5; color: #1a1a1a; }
    a { color: #1a56db; }
    .nav { background: #1a1a1a; padding: 0.75rem 1.5rem; display: flex; align-items: center; gap: 1.5rem; }
    .nav a { color: #fff; text-decoration: none; font-size: 0.9rem; }
    .nav a:hover { text-decoration: underline; }
    .nav form { margin-left: auto; }
    .nav button { background: none; border: none; color: #fff; font-size: 0.9rem; cursor: pointer; padding: 0; }
    .container { max-width: 960px; margin: 2rem auto; padding: 0 1.5rem; }
    .card { background: #fff; border: 1px solid #e0e0e0; border-radius: 6px; padding: 1.5rem; }
    .card-narrow { max-width: 400px; margin: 4rem auto; }
    h1 { font-size: 1.25rem; margin: 0 0 1rem; }
    .field { margin-bottom: 1rem; }
    .field label { display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 0.25rem; }
    .field input, .field textarea, .field select { width: 100%; box-sizing: border-box; padding: 0.5rem; border: 1px solid #ccc; border-radius: 4px; font-size: 0.9rem; }
    .field textarea { font-family: ui-monospace, monospace; min-height: 8rem; }
    .error { color: #b91c1c; font-size: 0.85rem; margin-top: 0.25rem; }
    .status { color: #15803d; font-size: 0.9rem; margin-bottom: 1rem; }
    .btn { display: inline-block; background: #1a1a1a; color: #fff; border: none; padding: 0.5rem 1rem; border-radius: 4px; font-size: 0.9rem; cursor: pointer; text-decoration: none; }
    .btn-danger { background: #b91c1c; }
    .btn-secondary { background: #fff; color: #1a1a1a; border: 1px solid #ccc; }
    table { width: 100%; border-collapse: collapse; margin-top: 1rem; }
    th, td { text-align: left; padding: 0.5rem 0.75rem; border-bottom: 1px solid #eee; font-size: 0.9rem; }
    th { font-size: 0.8rem; text-transform: uppercase; color: #666; }
    .toolbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; }
    .empty { color: #666; font-size: 0.9rem; padding: 1rem 0; }
</style>
