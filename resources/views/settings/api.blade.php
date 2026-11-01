<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>Akses API — Clipper Studio</title>
@include('partials.favicon')
<meta name="csrf-token" content="{{ csrf_token() }}" />
@include('partials.head')
@vite('resources/css/studio.css')
@include('partials.session-guard')
<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
<style>
  .tk-wrap { max-width: 720px; margin: 0 auto; padding: 40px 24px 96px; }
  .tk-wrap h1 { font-size: 28px; font-weight: 700; letter-spacing: -.02em; margin: 16px 0 8px; }
  .tk-wrap .lead { color: var(--text-muted); font-size: 14px; line-height: 1.6; margin: 0 0 28px; }
  .tk-form { display: flex; gap: 12px; flex-wrap: wrap; }
  .tk-input { flex: 1 1 220px; min-width: 0; background: var(--surface); border: 1px solid var(--border); border-radius: 10px; color: var(--text); font: inherit; font-size: 14px; padding: 12px 14px; }
  .tk-input:focus-visible { outline: 2px solid var(--accent-ink); outline-offset: 1px; }
  .tk-new { background: var(--surface); border: 1px solid var(--accent-ink); border-radius: var(--radius); padding: 16px 18px; margin-bottom: 20px; }
  .tk-new code { display: block; font-family: var(--font-mono); font-size: 13px; word-break: break-all; margin: 10px 0 12px; user-select: all; }
  .tk-list { list-style: none; margin: 0; padding: 0; }
  .tk-row { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 12px 0; border-top: 1px solid var(--border); }
  .tk-name { font-weight: 600; font-size: 14px; overflow-wrap: anywhere; }
  .tk-meta { font-family: var(--font-mono); font-size: 12px; color: var(--text-dim); }
  .tk-err { color: var(--danger); font-size: 13px; margin-top: 8px; }
  .tk-snip { font-family: var(--font-mono); font-size: 12.5px; background: var(--surface); border: 1px solid var(--border); border-radius: 10px; padding: 12px 14px; overflow-x: auto; white-space: pre; margin: 10px 0 0; }
  .tk-sec { margin-top: 36px; }
  .tk-sec h2 { font-size: 16px; margin: 0; }
  @media (max-width: 480px) { .tk-wrap { padding: 24px 16px 72px; } .tk-form .btn { width: 100%; } }
</style>
</head>
<body>
<div class="tk-wrap" x-data="apiTokens()" x-init="load()" x-cloak>
  <a href="{{ route('home') }}" class="btn-ghost btn-sm">← Kembali ke Studio</a>
  <h1>Akses API</h1>
  <p class="lead">Token untuk skrip dan agent AI (Claude, Codex) yang memakai akunmu: membuat project, render, dan publish. Kuota render ikut akunmu.</p>

  <div class="tk-new" x-show="created" role="status" aria-live="polite">
    <b>Token <span x-text="created?.name"></span></b>
    <div class="fhint">Salin sekarang. Token tidak ditampilkan lagi setelah halaman ini ditutup.</div>
    <code x-text="created?.token"></code>
    <button type="button" class="btn-secondary btn-sm" @click="copy()" x-text="copied ? 'Tersalin' : 'Salin token'"></button>
  </div>

  <form class="tk-form" @submit.prevent="create()">
    <label class="fblock-title" for="tk-name" style="width:100%;margin:0">Nama token</label>
    <input id="tk-name" class="tk-input" type="text" maxlength="100" required x-model="name" placeholder="mis. Claude Code laptop" />
    <button type="submit" class="btn" :disabled="busy">Buat token</button>
  </form>
  <div class="tk-err" x-show="error" x-text="error" role="alert"></div>

  <section class="tk-sec">
    <h2>Token aktif</h2>
    <p class="fhint" x-show="loaded && !tokens.length">Belum ada token.</p>
    <ul class="tk-list">
      <template x-for="t in tokens" :key="t.id">
        <li class="tk-row">
          <div>
            <div class="tk-name" x-text="t.name"></div>
            <div class="tk-meta" x-text="t.last_used_at ? 'Terakhir dipakai ' + fmt(t.last_used_at) : 'Belum pernah dipakai'"></div>
          </div>
          <button type="button" class="btn-secondary btn-sm" @click="revoke(t)" :aria-label="'Cabut token ' + t.name">Cabut</button>
        </li>
      </template>
    </ul>
  </section>

  <section class="tk-sec">
    <h2>Sambungkan agent</h2>
    <p class="fhint">Claude Code:</p>
    <pre class="tk-snip">claude mcp add --transport http clipper {{ url('/api/v1/mcp') }} \
  --header "Authorization: Bearer TOKEN_KAMU"</pre>
    <p class="fhint" style="margin-top:14px">REST: <code>{{ url('/api/v1') }}</code> dengan header <code>Authorization: Bearer TOKEN_KAMU</code>. Panduan lengkap ada di <code>docs/agent-api.md</code>.</p>
  </section>
</div>

<script>
function apiTokens() {
  const csrf = document.querySelector('meta[name=csrf-token]').content;
  const call = (method, url, body) => fetch(url, {
    method,
    headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
    body: body ? JSON.stringify(body) : undefined,
  }).then(async (r) => {
    const data = await r.json().catch(() => ({}));
    if (!r.ok) throw new Error(data.message || 'Gagal, coba lagi.');
    return data;
  });
  return {
    tokens: [], loaded: false, name: '', busy: false, error: '', created: null, copied: false,
    fmt: (iso) => new Date(iso).toLocaleString('id-ID', { dateStyle: 'medium', timeStyle: 'short' }),
    async load() {
      try { this.tokens = await call('GET', '/api/tokens'); } catch (e) { this.error = e.message; }
      this.loaded = true;
    },
    async create() {
      this.busy = true; this.error = '';
      try {
        this.created = await call('POST', '/api/tokens', { name: this.name });
        this.copied = false; this.name = '';
        await this.load();
      } catch (e) { this.error = e.message; }
      this.busy = false;
    },
    async revoke(t) {
      this.error = '';
      try { await call('DELETE', '/api/tokens/' + t.id); await this.load(); } catch (e) { this.error = e.message; }
    },
    async copy() {
      try { await navigator.clipboard.writeText(this.created.token); this.copied = true; } catch (e) { this.error = 'Salin manual dari kotak di atas.'; }
    },
  };
}
</script>
</body>
</html>
