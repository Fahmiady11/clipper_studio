<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>Preset tampilan · Clipper Studio</title>
@include('partials.favicon')
<meta name="csrf-token" content="{{ csrf_token() }}" />
@include('partials.head')
@vite('resources/css/studio.css')
@include('partials.session-guard')
<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
<style>
  .ps-wrap { max-width: 720px; margin: 0 auto; padding: 40px 24px 96px; }
  .ps-wrap h1 { font-size: 28px; font-weight: 700; letter-spacing: -.02em; margin: 16px 0 8px; }
  .ps-wrap .lead { color: var(--text-muted); font-size: 14px; line-height: 1.6; margin: 0 0 28px; }
  .ps-sec { margin-top: 32px; }
  .ps-sec h2 { font-size: 16px; margin: 0 0 4px; }
  .ps-sec > p { margin: 0 0 12px; font-size: 13px; color: var(--text-muted); }
  .ps-list { list-style: none; margin: 0; padding: 0; }
  .ps-row { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; padding: 14px 0; border-top: 1px solid var(--border); }
  .ps-name { font-weight: 600; font-size: 14.5px; overflow-wrap: anywhere; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
  .ps-tag { font-family: var(--font-mono); font-size: 10.5px; font-weight: 500; color: var(--text-muted); border: 1px solid var(--border-strong); border-radius: 6px; padding: 1px 6px; }
  .ps-tag.def { color: var(--on-accent); background: var(--accent); border-color: var(--accent); }
  .ps-sum { margin-top: 4px; font-size: 12.5px; color: var(--text-muted); line-height: 1.5; }
  .ps-acts { display: flex; gap: 6px; flex-wrap: wrap; justify-content: flex-end; flex-shrink: 0; }
  .ps-edit { display: flex; gap: 8px; margin-top: 2px; }
  .ps-edit .field { min-height: 38px; padding: 7px 12px; font-size: 14px; }
  .ps-err { color: var(--danger); font-size: 13px; margin-top: 6px; }
  .ps-acts .btn-danger:hover { color: var(--danger); }
  .ps-empty { padding: 14px 0; border-top: 1px solid var(--border); font-size: 13.5px; color: var(--text-muted); }
  @media (max-width: 560px) {
    .ps-wrap { padding: 24px 16px 72px; }
    .ps-row { flex-direction: column; gap: 10px; }
    .ps-acts { justify-content: flex-start; }
    .ps-edit { flex-wrap: wrap; }
  }
</style>
</head>
<body>
<div class="ps-wrap" x-data="presetsPage()" x-init="load()" x-cloak>
  <a href="{{ route('home') }}" class="btn-ghost btn-sm">← Kembali ke Studio</a>
  <h1>Preset tampilan</h1>
  <p class="lead">Satu klik untuk layout, efek, subtitle, gaya hook dan musik. Dipakai di Studio (langkah 2), di sumber Autopilot, dan lewat API. Preset default otomatis dipasang di setiap project baru.</p>

  <p class="ps-err" x-show="error" role="alert"><span x-text="error"></span> <button class="rs-link" type="button" @click="load()">Coba lagi</button></p>
  <p class="trim-muted" x-show="loading && !presets.length">Memuat preset…</p>

  <section class="ps-sec" x-show="presets.length">
    <h2>Preset buatanmu</h2>
    <p>Buat preset baru di Studio: atur tampilan di langkah 2 sampai 5, lalu <b>Simpan tampilan ini sebagai preset</b>.</p>
    <ul class="ps-list">
      <template x-for="p in own" :key="p.id">
        <li class="ps-row">
          <div style="flex:1;min-width:0">
            <div class="ps-name" x-show="editing !== p.id">
              <span x-text="p.name"></span>
              <span class="ps-tag def" x-show="p.id === defaultId">Default</span>
              <span class="ps-tag" x-show="p.brand">Ikut brand kit</span>
            </div>
            <form class="ps-edit" x-show="editing === p.id" @submit.prevent="rename(p)">
              <input class="field" maxlength="60" x-model="draft" :aria-label="'Nama baru untuk ' + p.name" :data-edit="p.id" @keydown.escape="editing = null">
              <button class="btn btn-sm" type="submit" :disabled="!draft.trim() || busy === p.id">Simpan</button>
              <button class="btn btn-ghost btn-sm" type="button" @click="editing = null">Batal</button>
            </form>
            <div class="ps-sum" x-text="p.summary.join(' · ')"></div>
            <div class="ps-err" x-show="rowError[p.id]" x-text="rowError[p.id]"></div>
          </div>
          <div class="ps-acts" x-show="editing !== p.id">
            <button class="btn btn-secondary btn-sm" type="button" @click="setDefault(p.id === defaultId ? null : p.id)" :disabled="busy === p.id"
                    x-text="p.id === defaultId ? 'Lepas default' : 'Jadikan default'"></button>
            <button class="btn btn-ghost btn-sm" type="button" @click="startRename(p)">Ganti nama</button>
            <button class="btn btn-ghost btn-sm btn-danger" type="button" @click="remove(p)" :disabled="busy === p.id">Hapus</button>
          </div>
        </li>
      </template>
      <li class="ps-empty" x-show="!own.length">Belum ada preset buatanmu.</li>
    </ul>
  </section>

  <section class="ps-sec" x-show="builtin.length">
    <h2>Bawaan</h2>
    <p>Tidak bisa diubah. Pasang di Studio, ubah sesukamu, lalu simpan sebagai preset sendiri.</p>
    <ul class="ps-list">
      <template x-for="p in builtin" :key="p.id">
        <li class="ps-row">
          <div style="flex:1;min-width:0">
            <div class="ps-name"><span x-text="p.name"></span><span class="ps-tag def" x-show="p.id === defaultId">Default</span></div>
            <div class="ps-sum" x-text="p.summary.join(' · ')"></div>
            <div class="ps-err" x-show="rowError[p.id]" x-text="rowError[p.id]"></div>
          </div>
          <div class="ps-acts">
            <button class="btn btn-secondary btn-sm" type="button" @click="setDefault(p.id === defaultId ? null : p.id)" :disabled="busy === p.id"
                    x-text="p.id === defaultId ? 'Lepas default' : 'Jadikan default'"></button>
          </div>
        </li>
      </template>
    </ul>
  </section>
</div>

<script>
function presetsPage() {
  const headers = () => ({ 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content });
  const api = async (url, opts = {}) => {
    const res = await fetch(url, { headers: headers(), ...opts });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error((data.errors ? Object.values(data.errors)[0][0] : data.message) || ('HTTP ' + res.status));
    return data;
  };

  return {
    presets: [], defaultId: null, loading: false, error: '', rowError: {}, busy: null, editing: null, draft: '',
    get own() { return this.presets.filter(p => !p.builtin); },
    get builtin() { return this.presets.filter(p => p.builtin); },
    async load() {
      this.loading = true; this.error = '';
      try {
        const d = await api('/api/presets');
        this.presets = d.presets; this.defaultId = d.default_id;
      } catch (e) { this.error = 'Preset gagal dimuat: ' + e.message; }
      finally { this.loading = false; }
    },
    async setDefault(id) {
      const key = id ?? this.defaultId;
      this.busy = key; this.rowError = {};
      try {
        const d = await api('/api/presets/default', { method: 'PUT', body: JSON.stringify({ preset_id: id }) });
        this.defaultId = d.default_id;
      } catch (e) { this.rowError = { [key]: e.message }; }
      finally { this.busy = null; }
    },
    startRename(p) {
      this.editing = p.id; this.draft = p.name; this.rowError = {};
      this.$nextTick(() => this.$root.querySelector(`[data-edit="${p.id}"]`)?.focus());
    },
    async rename(p) {
      this.busy = p.id; this.rowError = {};
      try {
        const d = await api(`/api/presets/${p.id}`, { method: 'PATCH', body: JSON.stringify({ name: this.draft.trim() }) });
        Object.assign(p, d.preset);
        this.editing = null;
      } catch (e) { this.rowError = { [p.id]: e.message }; }
      finally { this.busy = null; }
    },
    async remove(p) {
      if (!confirm(`Hapus preset "${p.name}"? Sumber Autopilot yang memakainya tetap memakai tampilan ini sebagai pengaturannya sendiri.`)) return;
      this.busy = p.id; this.rowError = {};
      try {
        await api(`/api/presets/${p.id}`, { method: 'DELETE' });
        this.presets = this.presets.filter(x => x.id !== p.id);
        if (this.defaultId === p.id) this.defaultId = null;
      } catch (e) { this.rowError = { [p.id]: e.message }; }
      finally { this.busy = null; }
    },
  };
}
</script>
</body>
</html>
