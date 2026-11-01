<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>Akun Meta · Clipper Studio</title>
@include('partials.favicon')
<meta name="csrf-token" content="{{ csrf_token() }}" />
@include('partials.head')
@vite('resources/css/studio.css')
@include('partials.session-guard')
<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
<style>
  /* Same frame as the other settings pages. One login can hold many Pages, so each login is a card
     and its Pages are rows inside it; the picker opens in place of the rows. */
  .mt-wrap { max-width: 720px; margin: 0 auto; padding: 40px 24px 96px; }
  .mt-wrap h1 { font-size: 28px; font-weight: 700; letter-spacing: -.02em; margin: 16px 0 8px; }
  .mt-wrap .lead { color: var(--text-muted); font-size: 14px; line-height: 1.6; margin: 0 0 24px; }
  .mt-sec h2 { font-size: 16px; margin: 0 0 4px; }
  .mt-note { font-size: 13px; line-height: 1.55; border-radius: 10px; padding: 10px 12px; margin: 0 0 20px; background: var(--surface-2); border: 1px solid var(--border); }
  .mt-note.bad { background: var(--danger-soft); border-color: var(--danger); }
  .mt-note.good { border-color: var(--accent-ink); }
  .mt-card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 16px; margin-top: 14px; }
  .mt-card-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
  .mt-who { font-size: 13px; color: var(--text-muted); }
  .mt-who b { color: var(--text); font-weight: 600; }
  .mt-acts { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
  .mt-acts .btn { min-height: 44px; }
  .mt-warn { font-size: 12.5px; color: var(--warn); line-height: 1.5; margin-top: 10px; }
  .mt-list { list-style: none; margin: 12px 0 0; padding: 0; }
  .mt-row { display: grid; grid-template-columns: 40px minmax(0, 1fr) auto; gap: 12px; align-items: center; padding: 10px 0; border-top: 1px solid var(--border); }
  .mt-pic { width: 40px; height: 40px; border-radius: 10px; background: var(--surface-3); overflow: hidden; display: flex; align-items: center; justify-content: center; font-family: var(--font-mono); font-size: 13px; font-weight: 600; color: var(--text-muted); }
  .mt-pic img { width: 100%; height: 100%; object-fit: cover; display: block; }
  .mt-name { font-weight: 600; font-size: 14px; overflow-wrap: anywhere; }
  .mt-sub { font-size: 12px; color: var(--text-dim); margin-top: 2px; }
  .mt-pick { display: grid; grid-template-columns: 22px 40px minmax(0, 1fr); gap: 12px; align-items: center; min-height: 56px; padding: 6px 0; border-top: 1px solid var(--border); cursor: pointer; }
  .mt-pick input { width: 18px; height: 18px; accent-color: var(--accent-ink); }
  .mt-pick.off { cursor: not-allowed; opacity: .6; }
  .mt-pick.ig { margin-left: 34px; border-top-style: dashed; }
  .mt-empty { font-size: 13px; color: var(--text-muted); line-height: 1.55; margin: 12px 0 0; }
  .mt-err { color: var(--danger); font-size: 13px; margin-top: 10px; }
  .mt-foot { margin-top: 18px; font-size: 12.5px; color: var(--text-dim); line-height: 1.55; }
  .mt-foot a, .mt-note a { color: var(--accent-ink); }
  @media (max-width: 480px) {
    .mt-wrap { padding: 24px 16px 72px; }
    .mt-row { grid-template-columns: 40px minmax(0, 1fr); }
    .mt-row .mt-acts { grid-column: 1 / -1; justify-content: flex-end; }
    .mt-card-head .mt-acts { width: 100%; }
    .mt-card-head .mt-acts .btn { flex: 1; }
  }
</style>
</head>
<body>
<div class="mt-wrap" x-data="metaAccounts()" x-init="init()" x-cloak>
  <a href="{{ route('home') }}" class="btn-ghost btn-sm">← Kembali ke Studio</a>
  <h1>Akun Meta</h1>
  <p class="lead">Tempat clip dari <a href="{{ route('calendar') }}" style="color:var(--accent-ink)">kalender</a> dan autopilot dipublikasikan: Reels di Page Facebook, Reels di akun Instagram profesional yang terhubung ke Page itu, dan video di Threads.</p>

  <div class="mt-note" :class="notice.kind" x-show="notice.text" role="status" x-text="notice.text"></div>
  <div class="mt-note bad" x-show="mediaProblem" role="note">
    <b>Instagram dan Threads belum bisa dipakai.</b> Keduanya mengambil video dari server ini lewat link, tapi <span x-text="mediaProblem"></span>
  </div>

  <section class="mt-sec">
    <h2>Facebook dan Instagram</h2>

    <p class="mt-empty" x-show="!loaded && !error">Memuat akun…</p>
    <p class="mt-err" x-show="error" x-text="error" role="alert"></p>

    <template x-if="loaded && !configured">
      <p class="mt-empty">Facebook belum dikonfigurasi di server. Isi <code>FACEBOOK_APP_ID</code>, <code>FACEBOOK_APP_SECRET</code>, dan <code>FACEBOOK_REDIRECT_URI</code> di <code>.env</code>.</p>
    </template>

    <template x-if="loaded && configured && fbAccounts.length === 0">
      <div class="mt-card">
        <p class="mt-empty" style="margin:0 0 12px">Belum ada akun Facebook. Login dengan akun yang menjadi admin atau editor Page, lalu pilih Page dan akun Instagram yang mau dipakai. Instagram harus akun profesional yang terhubung ke Page.</p>
        <a class="btn" href="/facebook/connect" style="min-height:44px">Hubungkan Facebook</a>
      </div>
    </template>

    <template x-for="a in fbAccounts" :key="a.id">
      <div class="mt-card">
        <div class="mt-card-head">
          <div class="mt-who">Login sebagai <b x-text="a.name || 'akun Facebook'"></b></div>
          <div class="mt-acts" x-show="picker.accountId !== a.id">
            <button type="button" class="btn btn-secondary btn-sm" @click="openPicker(a)">Pilih Page dan Instagram</button>
            <template x-if="confirming !== 'a' + a.id">
              <button type="button" class="btn btn-ghost btn-sm" @click="confirming = 'a' + a.id">Putuskan</button>
            </template>
            <template x-if="confirming === 'a' + a.id">
              <span class="mt-acts">
                <button type="button" class="btn btn-secondary btn-sm" @click="removeAccount(a)" :aria-label="'Ya, putuskan login ' + (a.name || '')">Ya, putuskan semuanya</button>
                <button type="button" class="btn btn-ghost btn-sm" @click="confirming = null">Batal</button>
              </span>
            </template>
          </div>
        </div>
        <p class="mt-warn" x-show="a.missing_scopes.length" x-text="'Izin ' + a.missing_scopes.join(', ') + ' tidak diberikan, jadi posting bisa gagal. Hubungkan lagi dan centang semua izin.'"></p>

        {{-- Connected Pages --}}
        <template x-if="picker.accountId !== a.id">
          <div>
            <p class="mt-empty" x-show="!a.targets.length">Belum ada Page atau akun Instagram yang dipilih dari login ini.</p>
            <ul class="mt-list" x-show="a.targets.length">
              <template x-for="t in a.targets" :key="t.id">
                <li class="mt-row">
                  <div class="mt-pic"><img x-show="t.picture_url" :src="t.picture_url" alt="" loading="lazy" referrerpolicy="no-referrer"><span x-show="!t.picture_url" x-text="initials(t.name)"></span></div>
                  <div>
                    <div class="mt-name" x-text="t.name || t.username || ('#' + t.id)"></div>
                    <div class="mt-sub" x-text="t.platform === 'instagram' ? 'Reels Instagram' + (t.username ? ' · @' + t.username : '') : 'Reels Facebook'"></div>
                  </div>
                  <div class="mt-acts">
                    <template x-if="confirming !== 't' + t.id">
                      <button type="button" class="btn btn-ghost btn-sm" @click="confirming = 't' + t.id" :aria-label="'Putuskan ' + (t.name || 'Page')">Putuskan</button>
                    </template>
                    <template x-if="confirming === 't' + t.id">
                      <span class="mt-acts">
                        <button type="button" class="btn btn-secondary btn-sm" @click="removeTarget(t)" :aria-label="'Ya, putuskan ' + (t.name || 'Page')">Ya, putuskan</button>
                        <button type="button" class="btn btn-ghost btn-sm" @click="confirming = null">Batal</button>
                      </span>
                    </template>
                  </div>
                </li>
              </template>
            </ul>
          </div>
        </template>

        {{-- Page picker --}}
        <template x-if="picker.accountId === a.id">
          <form @submit.prevent="savePicker(a)" @keydown.escape="closePicker()">
            <p class="mt-empty" x-show="picker.loading">Memuat Page dan Instagram dari Facebook…</p>
            <p class="mt-err" x-show="picker.error" x-text="picker.error" role="alert"></p>
            <p class="mt-empty" x-show="!picker.loading && !picker.error && picker.pages.length === 0">Akun ini tidak mengelola Page apa pun. Buat Page di Facebook, atau minta admin Page menambahkanmu, lalu hubungkan lagi.</p>
            <fieldset x-show="picker.pages.length" style="border:0;margin:12px 0 0;padding:0">
              <legend class="mt-who" style="margin-bottom:6px">Centang yang boleh menerima Reels:</legend>
              <template x-for="p in picker.pages" :key="p.id">
                <div>
                  <label class="mt-pick" :class="{ off: !p.can_publish }">
                    <input type="checkbox" :value="p.id" x-model="picker.chosen" :disabled="!p.can_publish">
                    <span class="mt-pic"><img x-show="p.picture_url" :src="p.picture_url" alt="" loading="lazy" referrerpolicy="no-referrer"><span x-show="!p.picture_url" x-text="initials(p.name)"></span></span>
                    <span>
                      <span class="mt-name" x-text="p.name || ('Page ' + p.id)"></span>
                      <span class="mt-sub" style="display:block" x-text="p.can_publish ? 'Page Facebook' : 'Kamu tidak punya izin membuat konten di Page ini.'"></span>
                    </span>
                  </label>
                  <template x-if="p.instagram">
                    <label class="mt-pick ig" :class="{ off: !p.can_publish }">
                      <input type="checkbox" :value="p.instagram.id" x-model="picker.igChosen" :disabled="!p.can_publish">
                      <span class="mt-pic"><img x-show="p.instagram.picture_url" :src="p.instagram.picture_url" alt="" loading="lazy" referrerpolicy="no-referrer"><span x-show="!p.instagram.picture_url">IG</span></span>
                      <span>
                        <span class="mt-name" x-text="'@' + (p.instagram.username || p.instagram.id)"></span>
                        <span class="mt-sub" style="display:block">Instagram, terhubung ke Page ini</span>
                      </span>
                    </label>
                  </template>
                </div>
              </template>
            </fieldset>
            <div class="mt-acts" style="margin-top:14px">
              <button type="submit" class="btn btn-sm" :disabled="picker.loading || picker.saving || !!picker.error" x-text="picker.saving ? 'Menyimpan…' : 'Simpan pilihan'"></button>
              <button type="button" class="btn btn-ghost btn-sm" @click="closePicker()">Batal</button>
            </div>
          </form>
        </template>
      </div>
    </template>

    <p class="mt-foot" x-show="loaded && configured && fbAccounts.length">
      Page dari akun Facebook lain? <a href="/facebook/connect">Hubungkan akun lain</a>.
      Instagram tidak muncul? Pastikan akunnya profesional (bisnis atau kreator) dan terhubung ke Page di pengaturan Instagram.
      Memutus tidak menghapus yang sudah tayang. Jadwal yang masih menunggu akan gagal dengan alasannya di kalender.
    </p>
  </section>

  <section class="mt-sec" style="margin-top:36px">
    <h2>Threads</h2>

    <template x-if="loaded && !threadsConfigured">
      <p class="mt-empty">Threads belum dikonfigurasi di server. Isi <code>THREADS_APP_ID</code>, <code>THREADS_APP_SECRET</code>, dan <code>THREADS_REDIRECT_URI</code> di <code>.env</code>.</p>
    </template>

    <template x-if="loaded && threadsConfigured && threadsAccounts.length === 0">
      <div class="mt-card">
        <p class="mt-empty" style="margin:0 0 12px">Belum ada profil Threads. Login Threads terpisah dari Facebook: masuk dengan profil yang mau dipakai.</p>
        <a class="btn" href="/threads/connect" style="min-height:44px">Hubungkan Threads</a>
      </div>
    </template>

    <template x-for="a in threadsAccounts" :key="a.id">
      <div class="mt-card">
        <ul class="mt-list" style="margin:0">
          <template x-for="t in a.targets" :key="t.id">
            <li class="mt-row" style="border-top:0;padding-top:0">
              <div class="mt-pic"><img x-show="t.picture_url" :src="t.picture_url" alt="" loading="lazy" referrerpolicy="no-referrer"><span x-show="!t.picture_url" x-text="initials(t.name)"></span></div>
              <div>
                <div class="mt-name" x-text="t.name || t.username"></div>
                <div class="mt-sub" x-text="'@' + t.username + ' · ' + tokenLabel(a)"></div>
              </div>
              <div class="mt-acts">
                <template x-if="confirming !== 'a' + a.id">
                  <button type="button" class="btn btn-ghost btn-sm" @click="confirming = 'a' + a.id" :aria-label="'Putuskan Threads @' + t.username">Putuskan</button>
                </template>
                <template x-if="confirming === 'a' + a.id">
                  <span class="mt-acts">
                    <button type="button" class="btn btn-secondary btn-sm" @click="removeAccount(a)" :aria-label="'Ya, putuskan Threads @' + t.username">Ya, putuskan</button>
                    <button type="button" class="btn btn-ghost btn-sm" @click="confirming = null">Batal</button>
                  </span>
                </template>
              </div>
            </li>
          </template>
        </ul>
      </div>
    </template>

    <p class="mt-foot" x-show="loaded && threadsConfigured && threadsAccounts.length">
      Profil lain? Keluar dulu dari Threads di browser ini, lalu <a href="/threads/connect">hubungkan lagi</a>.
      Akses Threads berlaku 60 hari dan diperpanjang otomatis setiap hari selama masih aktif.
    </p>
  </section>
</div>

<script>
function metaAccounts() {
  const csrf = () => document.querySelector('meta[name=csrf-token]').content;
  const errorText = (data, fallback) => (data && data.errors ? Object.values(data.errors)[0][0] : (data && data.message)) || fallback;
  const notices = name => ({
    connected: ['good', name === 'Facebook' ? 'Facebook terhubung. Pilih Page dan akun Instagram yang mau dipakai.' : 'Threads terhubung.'],
    denied: ['bad', 'Login ' + name + ' dibatalkan, tidak ada yang berubah.'],
    invalid_state: ['bad', 'Sesi login kedaluwarsa. Tekan Hubungkan ' + name + ' sekali lagi.'],
    error: ['bad', name + ' menolak login. Coba lagi. Kalau masih gagal, cek log server (clipper_api).'],
    not_configured: ['bad', name + ' belum dikonfigurasi di server.'],
  });

  return {
    configured: false, threadsConfigured: false, mediaProblem: null, accounts: [], loaded: false, error: '', confirming: null,
    notice: { kind: '', text: '' },
    picker: { accountId: null, pages: [], chosen: [], igChosen: [], loading: false, saving: false, error: '' },

    get fbAccounts() { return this.accounts.filter(a => a.provider === 'facebook'); },
    get threadsAccounts() { return this.accounts.filter(a => a.provider === 'threads'); },
    tokenLabel(a) {
      if (!a.token_expires_at) return 'akses aktif';
      const at = new Date(a.token_expires_at);
      return at < new Date() ? 'akses kedaluwarsa, hubungkan lagi' : 'akses sampai ' + at.toLocaleDateString('id-ID', { day: 'numeric', month: 'short', year: 'numeric' });
    },

    async init() {
      const q = new URLSearchParams(location.search);
      const result = q.get('facebook');
      const back = result ? ['Facebook', result] : q.get('threads') ? ['Threads', q.get('threads')] : null;
      if (back) {
        history.replaceState(null, '', location.pathname);
        const [kind, text] = notices(back[0])[back[1]] || ['bad', 'Gagal menghubungkan ' + back[0] + ' (' + back[1] + ').'];
        this.notice = { kind, text };
      }
      await this.load();
      // Straight from the login dialog: show that login's Pages to pick from
      const fresh = result === 'connected' && this.accounts.find(a => String(a.id) === q.get('account'));
      if (fresh) this.openPicker(fresh);
    },

    initials(name) { return String(name || '?').trim().split(/\s+/).slice(0, 2).map(w => w[0]).join('').toUpperCase(); },

    async request(url, opts = {}) {
      const res = await fetch(url, { ...opts, headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() } });
      if (res.status === 401) { location.href = '/login?expired=1'; throw new Error('Sesi habis.'); }
      if (res.status === 419) { location.reload(); throw new Error('Sesi habis.'); }
      const data = await res.json().catch(() => null);
      if (!res.ok) throw new Error(errorText(data, 'Server menjawab ' + res.status + '.'));
      return data;
    },

    async load() {
      try {
        const data = await this.request('/api/meta/accounts');
        this.configured = data.configured;
        this.threadsConfigured = data.threads_configured;
        this.mediaProblem = data.media_problem;
        this.accounts = data.accounts;
        this.error = '';
      } catch (e) { this.error = 'Akun gagal dimuat: ' + e.message; }
      this.loaded = true;
    },

    async openPicker(a) {
      this.confirming = null;
      this.picker = { accountId: a.id, pages: [], chosen: [], igChosen: [], loading: true, saving: false, error: '' };
      try {
        const data = await this.request(`/api/meta/accounts/${a.id}/pages`);
        this.picker.pages = data.pages;
        this.picker.chosen = data.pages.filter(p => p.connected).map(p => p.id);
        this.picker.igChosen = data.pages.filter(p => p.instagram?.connected).map(p => p.instagram.id);
      } catch (e) { this.picker.error = e.message; }
      this.picker.loading = false;
    },
    closePicker() { this.picker.accountId = null; },

    async savePicker(a) {
      this.picker.saving = true; this.picker.error = '';
      try {
        const data = await this.request(`/api/meta/accounts/${a.id}/pages`, { method: 'PUT', body: JSON.stringify({ page_ids: this.picker.chosen, instagram_ids: this.picker.igChosen }) });
        this.notice = { kind: 'good', text: data.connected ? data.connected + ' akun siap menerima Reels.' : 'Tidak ada yang dipilih dari login ini.' };
        this.closePicker();
        await this.load();
      } catch (e) { this.picker.error = e.message; }
      finally { this.picker.saving = false; }
    },

    async removeTarget(t) {
      this.confirming = null;
      try { await this.request(`/api/meta/targets/${t.id}`, { method: 'DELETE' }); await this.load(); }
      catch (e) { this.error = e.message; }
    },
    async removeAccount(a) {
      this.confirming = null;
      try { await this.request(`/api/meta/accounts/${a.id}`, { method: 'DELETE' }); await this.load(); }
      catch (e) { this.error = e.message; }
    },
  };
}
</script>
</body>
</html>
