<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>Akun terhubung · Clipper Studio</title>
@include('partials.favicon')
<meta name="csrf-token" content="{{ csrf_token() }}" />
@include('partials.head')
@vite('resources/css/studio.css')
@include('partials.session-guard')
<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
<style>
  /* Same frame as the other settings pages. One section per platform: its accounts first (which one
     is the default), then what autopilot does there. Lime only marks the default badge and primary actions. */
  .ac-wrap { max-width: 760px; margin: 0 auto; padding: 40px 24px 96px; }
  .ac-wrap h1 { font-size: 28px; font-weight: 700; letter-spacing: -.02em; margin: 16px 0 8px; }
  .ac-wrap .lead { color: var(--text-muted); font-size: 14px; line-height: 1.6; margin: 0 0 20px; }
  .ac-jump { display: flex; flex-wrap: wrap; gap: 8px; margin: 0 0 8px; padding: 0; list-style: none; }
  .ac-jump a { display: inline-flex; align-items: center; gap: 6px; min-height: 44px; padding: 0 14px; border-radius: 10px; border: 1px solid var(--border); background: var(--surface); color: var(--text); font-size: 13px; text-decoration: none; }
  .ac-jump a:hover { background: var(--surface-2); }
  .ac-jump small { font-family: var(--font-mono); font-size: 11px; color: var(--text-dim); }
  .ac-jump small.bad { color: var(--warn); }
  .ac-note { font-size: 13px; line-height: 1.55; border-radius: 10px; padding: 10px 12px; margin: 12px 0 0; background: var(--surface-2); border: 1px solid var(--border); }
  .ac-note.bad { background: var(--danger-soft); border-color: var(--danger); }
  .ac-note.good { border-color: var(--accent-ink); }
  .ac-sec { margin-top: 32px; scroll-margin-top: 80px; }
  .ac-sec-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
  .ac-sec-head h2 { font-size: 18px; margin: 0; }
  .ac-sec.flash .ac-card { box-shadow: 0 0 0 2px var(--warn); }
  .ac-card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 16px; margin-top: 12px; }
  .ac-acts { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
  .ac-acts .btn, .ac-sec-head .btn { min-height: 44px; }
  .ac-list { list-style: none; margin: 0; padding: 0; }
  .ac-row { display: grid; grid-template-columns: 40px minmax(0, 1fr) auto; gap: 12px; align-items: center; padding: 12px 0; border-top: 1px solid var(--border); }
  .ac-row:first-of-type { border-top: 0; padding-top: 0; }
  .ac-pic { width: 40px; height: 40px; border-radius: 10px; background: var(--surface-3); overflow: hidden; display: flex; align-items: center; justify-content: center; font-family: var(--font-mono); font-size: 13px; font-weight: 600; color: var(--text-muted); }
  .ac-pic img { width: 100%; height: 100%; object-fit: cover; display: block; }
  .ac-name { font-weight: 600; font-size: 14px; overflow-wrap: anywhere; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
  .ac-badge { font-family: var(--font-mono); font-size: 10.5px; font-weight: 600; padding: 2px 8px; border-radius: 999px; background: var(--accent); color: var(--on-accent); }
  .ac-sub { font-size: 12px; color: var(--text-dim); margin-top: 2px; overflow-wrap: anywhere; }
  .ac-warn { font-size: 12.5px; color: var(--warn); line-height: 1.5; margin-top: 4px; }
  .ac-warn a, .ac-foot a, .ac-note a { color: var(--accent-ink); }
  .ac-empty { font-size: 13px; color: var(--text-muted); line-height: 1.55; margin: 0; }
  .ac-err { color: var(--danger); font-size: 13px; margin-top: 10px; }
  .ac-foot { margin-top: 10px; font-size: 12.5px; color: var(--text-dim); line-height: 1.55; }
  /* Autopost */
  .ac-auto { margin-top: 14px; padding-top: 14px; border-top: 1px dashed var(--border-strong); display: flex; flex-direction: column; gap: 12px; }
  .ac-auto-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
  .ac-auto-head b { font-size: 14px; }
  .ac-auto-head span { display: block; font-size: 12.5px; color: var(--text-muted); font-weight: 400; margin-top: 2px; line-height: 1.5; }
  .ac-grid { display: grid; grid-template-columns: 120px minmax(0, 1fr); gap: 12px; align-items: start; }
  .ac-label { display: block; font-size: 12px; font-weight: 500; color: var(--text-muted); margin-bottom: 6px; }
  .ac-slots { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
  .ac-slot { display: inline-flex; align-items: center; background: var(--surface-2); border: 1px solid var(--border); border-radius: 10px; }
  .ac-slot input { width: 104px; min-height: 44px; border: 0; background: transparent; color: var(--text); font-family: var(--font-mono); font-size: 14px; padding: 0 4px 0 12px; }
  .ac-slot button { width: 40px; min-height: 44px; border: 0; background: none; color: var(--text-dim); font-size: 18px; cursor: pointer; border-radius: 0 10px 10px 0; }
  .ac-slot button:hover { color: var(--danger); }
  .ac-tz { font-size: 12px; color: var(--text-dim); margin-top: 8px; }
  .ac-tz b { font-family: var(--font-mono); font-weight: 500; color: var(--text-muted); }
  .ac-saved { font-size: 12.5px; color: var(--text-muted); }
  /* Facebook logins and the Page / Instagram picker */
  .ac-login { margin-top: 14px; padding-top: 14px; border-top: 1px dashed var(--border-strong); }
  .ac-login-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; font-size: 13px; color: var(--text-muted); }
  .ac-login-head b { color: var(--text); }
  .ac-pick { display: grid; grid-template-columns: 22px 40px minmax(0, 1fr); gap: 12px; align-items: center; min-height: 56px; padding: 6px 0; border-top: 1px solid var(--border); cursor: pointer; }
  .ac-pick input { width: 18px; height: 18px; accent-color: var(--accent-ink); }
  .ac-pick.off { cursor: not-allowed; opacity: .6; }
  .ac-pick.ig { margin-left: 34px; border-top-style: dashed; }
  @media (max-width: 560px) {
    .ac-wrap { padding: 24px 16px 72px; }
    .ac-row { grid-template-columns: 40px minmax(0, 1fr); }
    .ac-row > .ac-acts { grid-column: 1 / -1; justify-content: flex-end; }
    .ac-grid { grid-template-columns: minmax(0, 1fr); }
    .ac-sec-head .btn { width: 100%; justify-content: center; }
  }
</style>
</head>
<body>
<div class="ac-wrap" x-data="connectedAccounts()" x-init="init()" x-cloak>
  <a href="{{ route('home') }}" class="btn-ghost btn-sm">← Kembali ke Studio</a>
  <h1>Akun terhubung</h1>
  <p class="lead">Semua akun tempat clip dipublikasikan. Akun <b>utama</b> tiap platform dipakai Studio, <a href="{{ route('calendar') }}" style="color:var(--accent-ink)">kalender</a>, dan autopilot. Di kalender kamu masih bisa memilih akun lain untuk satu jadwal.</p>

  <ul class="ac-jump" x-show="loaded" aria-label="Lompat ke platform">
    <template x-for="p in platforms" :key="p.id">
      <li><a :href="'#' + p.id">
        <span x-text="p.name"></span>
        <small :class="{ bad: p.accounts.some(a => a.reconnect) }" x-text="jumpLabel(p)"></small>
      </a></li>
    </template>
  </ul>

  <div class="ac-note" :class="notice.kind" x-show="notice.text" role="status" x-text="notice.text"></div>
  <div class="ac-note bad" x-show="mediaProblem" role="note">
    <b>Instagram dan Threads belum bisa dipakai.</b> Keduanya mengambil video dari server ini lewat link, tapi <span x-text="mediaProblem"></span>
  </div>
  <p class="ac-empty" style="margin-top:20px" x-show="!loaded && !error">Memuat akun…</p>
  <p class="ac-err" x-show="error" x-text="error" role="alert"></p>

  <template x-for="p in platforms" :key="p.id">
    <section class="ac-sec" :id="p.id" :class="{ flash: flash === p.id }" :aria-labelledby="'ac-h-' + p.id">
      <div class="ac-sec-head">
        <h2 :id="'ac-h-' + p.id" x-text="p.name"></h2>
        <template x-if="p.configured && p.id !== 'instagram'">
          <a class="btn" :class="{ 'btn-secondary': p.accounts.length }" :href="p.connect_url" x-text="p.accounts.length ? 'Tambah akun' : 'Hubungkan ' + p.name"></a>
        </template>
        <template x-if="p.configured && p.id === 'instagram'">
          <button type="button" class="btn" :class="{ 'btn-secondary': p.accounts.length }" @click="pickInstagram()" x-text="logins.length ? 'Pilih akun dari Page' : 'Hubungkan lewat Facebook'"></button>
        </template>
      </div>

      <div class="ac-card">
        <template x-if="!p.configured">
          <p class="ac-empty" x-text="p.name + ' belum dikonfigurasi di server. Isi kredensialnya di .env (lihat .env.example).'"></p>
        </template>
        <template x-if="p.configured && !p.accounts.length">
          <p class="ac-empty" x-text="INFO[p.id].empty"></p>
        </template>

        <ul class="ac-list" x-show="p.accounts.length">
          <template x-for="a in p.accounts" :key="a.id">
            <li class="ac-row">
              <div class="ac-pic"><img x-show="a.picture_url" :src="a.picture_url" alt="" loading="lazy" referrerpolicy="no-referrer"><span x-show="!a.picture_url" x-text="initials(a.name)"></span></div>
              <div>
                <div class="ac-name"><span x-text="a.name || ('#' + a.id)"></span><span class="ac-badge" x-show="a.id === p.default_id">Utama</span></div>
                <div class="ac-sub" x-show="a.username" x-text="'@' + a.username"></div>
                <div class="ac-warn" x-show="a.problem">
                  <span x-text="a.problem"></span>
                  <a x-show="a.reconnect" :href="p.connect_url">Hubungkan ulang</a>
                </div>
              </div>
              <div class="ac-acts">
                <button type="button" class="btn btn-secondary btn-sm" x-show="p.accounts.length > 1 && a.id !== p.default_id" @click="setDefault(p, a)" :aria-label="'Jadikan ' + (a.name || 'akun ini') + ' akun utama ' + p.name">Jadikan utama</button>
                <template x-if="confirming !== p.id + a.id">
                  <button type="button" class="btn btn-ghost btn-sm" @click="confirming = p.id + a.id" :aria-label="'Putuskan ' + (a.name || 'akun') + ' dari ' + p.name">Putuskan</button>
                </template>
                <template x-if="confirming === p.id + a.id">
                  <span class="ac-acts">
                    <button type="button" class="btn btn-secondary btn-sm" @click="remove(p, a)" :aria-label="'Ya, putuskan ' + (a.name || 'akun')">Ya, putuskan</button>
                    <button type="button" class="btn btn-ghost btn-sm" @click="confirming = null">Batal</button>
                  </span>
                </template>
              </div>
            </li>
          </template>
        </ul>

        {{-- Facebook logins: Pages and their Instagram accounts are picked per login --}}
        <template x-if="p.id === 'facebook' && logins.length">
          <div>
            <template x-for="l in logins" :key="l.id">
              <div class="ac-login">
                <div class="ac-login-head">
                  <span>Login Facebook <b x-text="l.name || ''"></b></span>
                  <span class="ac-acts" x-show="picker.loginId !== l.id">
                    <button type="button" class="btn btn-secondary btn-sm" @click="openPicker(l)">Pilih Page dan Instagram</button>
                    <template x-if="confirming !== 'login' + l.id">
                      <button type="button" class="btn btn-ghost btn-sm" @click="confirming = 'login' + l.id">Putuskan login</button>
                    </template>
                    <template x-if="confirming === 'login' + l.id">
                      <span class="ac-acts">
                        <button type="button" class="btn btn-secondary btn-sm" @click="removeLogin(l)">Ya, putuskan semua Page dan Instagram-nya</button>
                        <button type="button" class="btn btn-ghost btn-sm" @click="confirming = null">Batal</button>
                      </span>
                    </template>
                  </span>
                </div>
                <p class="ac-warn" x-show="l.missing_scopes.length" x-text="'Izin ' + l.missing_scopes.join(', ') + ' tidak diberikan, jadi posting bisa gagal. Hubungkan ulang dan centang semua izin.'"></p>

                <template x-if="picker.loginId === l.id">
                  <form @submit.prevent="savePicker(l)" @keydown.escape="picker.loginId = null">
                    <p class="ac-empty" style="margin-top:10px" x-show="picker.loading">Memuat Page dan Instagram dari Facebook…</p>
                    <p class="ac-err" x-show="picker.error" x-text="picker.error" role="alert"></p>
                    <p class="ac-empty" style="margin-top:10px" x-show="!picker.loading && !picker.error && !picker.pages.length">Login ini tidak mengelola Page apa pun. Buat Page di Facebook, atau minta admin Page menambahkanmu, lalu hubungkan lagi.</p>
                    <fieldset x-show="picker.pages.length" style="border:0;margin:10px 0 0;padding:0">
                      <legend class="ac-empty" style="margin-bottom:6px">Centang yang boleh menerima Reels:</legend>
                      <template x-for="pg in picker.pages" :key="pg.id">
                        <div>
                          <label class="ac-pick" :class="{ off: !pg.can_publish }">
                            <input type="checkbox" :value="pg.id" x-model="picker.chosen" :disabled="!pg.can_publish">
                            <span class="ac-pic"><img x-show="pg.picture_url" :src="pg.picture_url" alt="" loading="lazy" referrerpolicy="no-referrer"><span x-show="!pg.picture_url" x-text="initials(pg.name)"></span></span>
                            <span>
                              <span class="ac-name" x-text="pg.name || ('Page ' + pg.id)"></span>
                              <span class="ac-sub" style="display:block" x-text="pg.can_publish ? 'Page Facebook' : 'Kamu tidak punya izin membuat konten di Page ini.'"></span>
                            </span>
                          </label>
                          <template x-if="pg.instagram">
                            <label class="ac-pick ig" :class="{ off: !pg.can_publish }">
                              <input type="checkbox" :value="pg.instagram.id" x-model="picker.igChosen" :disabled="!pg.can_publish">
                              <span class="ac-pic"><img x-show="pg.instagram.picture_url" :src="pg.instagram.picture_url" alt="" loading="lazy" referrerpolicy="no-referrer"><span x-show="!pg.instagram.picture_url">IG</span></span>
                              <span>
                                <span class="ac-name" x-text="'@' + (pg.instagram.username || pg.instagram.id)"></span>
                                <span class="ac-sub" style="display:block">Instagram, terhubung ke Page ini</span>
                              </span>
                            </label>
                          </template>
                        </div>
                      </template>
                    </fieldset>
                    <div class="ac-acts" style="margin-top:12px">
                      <button type="submit" class="btn btn-sm" :disabled="picker.loading || picker.saving || !!picker.error" x-text="picker.saving ? 'Menyimpan…' : 'Simpan pilihan'"></button>
                      <button type="button" class="btn btn-ghost btn-sm" @click="picker.loginId = null">Batal</button>
                    </div>
                  </form>
                </template>
              </div>
            </template>
          </div>
        </template>

        {{-- What autopilot does on this platform --}}
        <template x-if="p.accounts.length && ap[p.id]">
          <div class="ac-auto">
            <div class="ac-auto-head">
              <div>
                <b x-text="INFO[p.id].autoTitle"></b>
                <span x-text="INFO[p.id].autoText"></span>
              </div>
              <button class="tgl" :class="{ on: ap[p.id].enabled }" @click="saveAutopost(p, !ap[p.id].enabled)" type="button" role="switch"
                      :aria-checked="ap[p.id].enabled ? 'true' : 'false'" :aria-label="INFO[p.id].autoTitle" :disabled="ap[p.id].saving">
                <span class="tgl-text" x-text="ap[p.id].enabled ? 'Aktif' : 'Nonaktif'"></span><span class="tgl-track"></span>
              </button>
            </div>
            <template x-if="p.id !== 'tiktok'">
              <div class="ac-grid">
                <label>
                  <span class="ac-label">Maks per hari</span>
                  <input class="field" type="number" min="1" max="10" x-model.number="ap[p.id].max_per_day" style="min-height:44px;font-family:var(--font-mono)">
                </label>
                <div>
                  <span class="ac-label" :id="'ac-slots-' + p.id">Jam upload</span>
                  <div class="ac-slots" role="group" :aria-labelledby="'ac-slots-' + p.id">
                    <template x-for="(t, i) in ap[p.id].slot_times" :key="i">
                      <span class="ac-slot">
                        <input type="time" x-model="ap[p.id].slot_times[i]" :aria-label="'Jam upload ' + (i + 1)">
                        <button type="button" @click="ap[p.id].slot_times.splice(i, 1)" x-show="ap[p.id].slot_times.length > 1" :aria-label="'Hapus jam ' + t">×</button>
                      </span>
                    </template>
                    <button class="btn btn-ghost btn-sm" type="button" style="min-height:44px" @click="addSlot(p.id)" x-show="ap[p.id].slot_times.length < 10">Tambah jam</button>
                  </div>
                  <div class="ac-tz">Zona waktu <b x-text="ap[p.id].timezone"></b>, ikut browser ini.</div>
                </div>
              </div>
            </template>
            <p class="ac-foot" style="margin:0" x-show="INFO[p.id].note" x-text="INFO[p.id].note"></p>
            <div class="ac-err" style="margin:0" x-show="ap[p.id].error" x-text="ap[p.id].error" role="alert"></div>
            <div class="ac-acts" x-show="p.id !== 'tiktok'">
              <button class="btn btn-sm" type="button" style="min-height:44px" @click="saveAutopost(p, ap[p.id].enabled)" :disabled="ap[p.id].saving" x-text="ap[p.id].saving ? 'Menyimpan…' : 'Simpan jam upload'"></button>
              <span class="ac-saved" x-show="ap[p.id].savedMsg" x-text="ap[p.id].savedMsg" role="status"></span>
            </div>
            <span class="ac-saved" x-show="p.id === 'tiktok' && ap[p.id].savedMsg" x-text="ap[p.id].savedMsg" role="status"></span>
          </div>
        </template>
      </div>
      <p class="ac-foot" x-show="p.configured && INFO[p.id].foot" x-text="INFO[p.id].foot"></p>
    </section>
  </template>
</div>

<script>
function connectedAccounts() {
  const csrf = () => document.querySelector('meta[name=csrf-token]').content;
  const errorText = (data, fallback) => (data && data.errors ? Object.values(data.errors)[0][0] : (data && data.message)) || fallback;
  const BACK = {
    connected: name => ['good', name + ' terhubung.'],
    denied: name => ['bad', 'Login ' + name + ' dibatalkan, tidak ada yang berubah.'],
    invalid_state: name => ['bad', 'Sesi login kedaluwarsa. Tekan Hubungkan ' + name + ' sekali lagi.'],
    error: name => ['bad', name + ' menolak login. Coba lagi. Kalau masih gagal, cek log server (clipper_api).'],
    not_configured: name => ['bad', name + ' belum dikonfigurasi di server.'],
  };
  const NAMES = { tiktok: 'TikTok', youtube: 'YouTube', facebook: 'Facebook', instagram: 'Instagram', threads: 'Threads' };

  return {
    INFO: {
      tiktok: {
        empty: 'Belum ada akun TikTok. Hubungkan akun yang mau menerima clip.',
        autoTitle: 'Kirim ke TikTok saat clip di-approve',
        autoText: 'Clip masuk inbox TikTok akun utama, lalu kamu selesaikan posting-nya di aplikasi TikTok.',
      },
      youtube: {
        empty: 'Belum ada channel YouTube. Hubungkan channel yang mau menerima Shorts.',
        autoTitle: 'Upload otomatis ke YouTube Shorts',
        autoText: 'Clip yang di-approve masuk kalender di jam upload kosong berikutnya.',
        note: 'Selama app belum lolos audit YouTube API, YouTube mengunci video jadi privat. Ubah ke publik dari YouTube Studio.',
      },
      facebook: {
        empty: 'Belum ada Page Facebook. Login dengan akun yang menjadi admin atau editor Page, lalu pilih Page-nya.',
        autoTitle: 'Upload otomatis ke Reels Facebook',
        autoText: 'Clip yang di-approve masuk kalender di jam upload kosong berikutnya.',
        note: 'Reels di Page selalu publik. Facebook hanya menerima video vertikal 3 sampai 90 detik.',
        foot: 'Memutus tidak menghapus Reels yang sudah tayang. Jadwal yang masih menunggu akan gagal dengan alasannya di kalender.',
      },
      instagram: {
        empty: 'Belum ada akun Instagram. Akunnya harus profesional (bisnis atau kreator) dan terhubung ke Page Facebook; pilih dari Page di bagian Facebook.',
        autoTitle: 'Upload otomatis ke Reels Instagram',
        autoText: 'Clip yang di-approve masuk kalender di jam upload kosong berikutnya.',
        note: 'Instagram membatasi 50 posting lewat API per 24 jam dan mengunduh video lewat APP_URL.',
      },
      threads: {
        empty: 'Belum ada profil Threads. Login Threads terpisah dari Facebook: masuk dengan profil yang mau dipakai.',
        autoTitle: 'Upload otomatis ke Threads',
        autoText: 'Clip yang di-approve masuk kalender di jam upload kosong berikutnya.',
        note: 'Teksnya caption plus satu hashtag, maksimal 500 karakter. Video maksimal 5 menit.',
        foot: 'Profil lain? Keluar dulu dari Threads di browser ini, lalu tambah akun. Akses Threads diperpanjang otomatis setiap hari.',
      },
    },
    platforms: [], logins: [], mediaProblem: null, loaded: false, error: '', confirming: null, flash: null,
    notice: { kind: '', text: '' },
    ap: {},
    picker: { loginId: null, pages: [], chosen: [], igChosen: [], loading: false, saving: false, error: '' },

    async init() {
      const q = new URLSearchParams(location.search);
      const back = Object.keys(NAMES).find(p => q.get(p));
      const reconnect = q.get('reconnect');
      if (back || reconnect) history.replaceState(null, '', location.pathname);
      if (back) {
        const [kind, text] = (BACK[q.get(back)] || (n => ['bad', 'Gagal menghubungkan ' + n + ' (' + q.get(back) + ').']))(NAMES[back]);
        this.notice = { kind, text: back === 'facebook' && q.get(back) === 'connected' ? 'Facebook terhubung. Pilih Page dan akun Instagram yang mau dipakai.' : text };
      }
      if (reconnect && NAMES[reconnect]) {
        this.notice = { kind: 'bad', text: 'Akses ' + NAMES[reconnect] + ' perlu diperbarui. Tekan Hubungkan ulang di akun yang ditandai.' };
      }
      await this.load();
      const focus = reconnect || back;
      if (focus && NAMES[focus]) this.$nextTick(() => { document.getElementById(focus)?.scrollIntoView({ block: 'start' }); this.flash = reconnect ? focus : null; });
      // Straight from the Facebook dialog: show that login's Pages to pick from
      const fresh = q.get('facebook') === 'connected' && this.logins.find(l => String(l.id) === q.get('account'));
      if (fresh) this.openPicker(fresh);
    },

    initials(name) { return String(name || '?').trim().split(/\s+/).slice(0, 2).map(w => w[0]).join('').toUpperCase(); },
    jumpLabel(p) {
      if (!p.configured) return 'belum diatur';
      if (p.accounts.some(a => a.reconnect)) return 'perlu dicek';
      return p.accounts.length ? p.accounts.length + ' akun' : 'belum ada';
    },

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
        const data = await this.request('/api/accounts');
        const browserTz = Intl.DateTimeFormat().resolvedOptions().timeZone;
        data.platforms.forEach(p => {
          const a = p.autopost;
          // Unsaved edits survive a reload of the list; slots follow the browser until saved once
          this.ap[p.id] = this.ap[p.id] && this.loaded ? { ...this.ap[p.id], enabled: a.enabled } : {
            enabled: a.enabled, max_per_day: a.max_per_day, slot_times: [...a.slot_times],
            timezone: a.saved ? a.timezone : (browserTz || a.timezone), saving: false, error: '', savedMsg: '',
          };
        });
        this.platforms = data.platforms;
        this.logins = data.facebook_logins;
        this.mediaProblem = data.media_problem;
        this.error = '';
      } catch (e) { this.error = 'Akun gagal dimuat: ' + e.message; }
      this.loaded = true;
    },

    async setDefault(p, a) {
      try { await this.request(`/api/accounts/${p.id}/default`, { method: 'PUT', body: JSON.stringify({ account_id: a.id }) }); await this.load(); }
      catch (e) { this.error = e.message; }
    },
    async remove(p, a) {
      this.confirming = null;
      try { await this.request(`/api/accounts/${p.id}/${a.id}`, { method: 'DELETE' }); await this.load(); }
      catch (e) { this.error = e.message; }
    },
    async removeLogin(l) {
      this.confirming = null;
      try { await this.request(`/api/meta/accounts/${l.id}`, { method: 'DELETE' }); await this.load(); }
      catch (e) { this.error = e.message; }
    },

    addSlot(platform) {
      const slots = this.ap[platform].slot_times;
      const h = (parseInt(slots[slots.length - 1] || '09:00', 10) + 3) % 24;
      slots.push(String(h).padStart(2, '0') + ':00');
    },
    async saveAutopost(p, enabled) {
      const st = this.ap[p.id];
      st.error = ''; st.savedMsg = '';
      if (p.id !== 'tiktok') {
        if (!(st.max_per_day >= 1 && st.max_per_day <= 10)) { st.error = 'Maks per hari antara 1 dan 10 clip.'; return; }
        if (st.slot_times.some(t => !t)) { st.error = 'Ada jam upload yang masih kosong.'; return; }
      }
      st.saving = true;
      try {
        const body = p.id === 'tiktok' ? { enabled } : { enabled, max_per_day: st.max_per_day, slot_times: st.slot_times, timezone: st.timezone };
        const d = await this.request('/api/autopilot/' + p.id, { method: 'PUT', body: JSON.stringify(body) });
        st.enabled = d.enabled;
        if (d.slot_times) st.slot_times = [...d.slot_times];
        st.savedMsg = d.enabled ? 'Tersimpan, aktif.' : 'Tersimpan, nonaktif.';
      } catch (e) { st.error = e.message; }
      finally { st.saving = false; }
    },

    // Instagram accounts come from the Pages of a Facebook login
    pickInstagram() {
      if (!this.logins.length) { location.href = '/facebook/connect'; return; }
      document.getElementById('facebook')?.scrollIntoView({ block: 'start' });
      this.openPicker(this.logins[0]);
    },
    async openPicker(l) {
      this.confirming = null;
      this.picker = { loginId: l.id, pages: [], chosen: [], igChosen: [], loading: true, saving: false, error: '' };
      try {
        const data = await this.request(`/api/meta/accounts/${l.id}/pages`);
        this.picker.pages = data.pages;
        this.picker.chosen = data.pages.filter(p => p.connected).map(p => p.id);
        this.picker.igChosen = data.pages.filter(p => p.instagram?.connected).map(p => p.instagram.id);
      } catch (e) { this.picker.error = e.message; }
      this.picker.loading = false;
    },
    async savePicker(l) {
      this.picker.saving = true; this.picker.error = '';
      try {
        const data = await this.request(`/api/meta/accounts/${l.id}/pages`, { method: 'PUT', body: JSON.stringify({ page_ids: this.picker.chosen, instagram_ids: this.picker.igChosen }) });
        this.notice = { kind: 'good', text: data.connected ? data.connected + ' Page dan akun Instagram siap menerima Reels.' : 'Tidak ada yang dipilih dari login ini.' };
        this.picker.loginId = null;
        await this.load();
      } catch (e) { this.picker.error = e.message; }
      finally { this.picker.saving = false; }
    },
  };
}
</script>
</body>
</html>
