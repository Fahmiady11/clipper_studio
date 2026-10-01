<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>Autopilot — Clipper Studio</title>
<meta name="csrf-token" content="{{ csrf_token() }}" />
<link rel="preconnect" href="https://fonts.googleapis.com" />
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&family=Roboto:wght@400;700;900&family=Montserrat:wght@600;700;800&family=Oswald:wght@500;700&family=Bebas+Neue&display=swap" rel="stylesheet" />
@vite('resources/css/studio.css')
@include('partials.session-guard')
@include('partials.clip-preview-js')
<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
<style>
  .ap-wrap { max-width: 1120px; margin: 0 auto; padding: 40px 24px 96px; }
  .ap-head h1 { font-size: 28px; font-weight: 700; letter-spacing: -.02em; margin: 0 0 8px; }
  .ap-head p { color: var(--text-muted); margin: 0; font-size: 14px; line-height: 1.6; max-width: 720px; }

  .ap-section { margin-top: 44px; }
  .ap-section-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; margin-bottom: 14px; }
  .ap-section-head h2 { font-size: 17px; font-weight: 600; margin: 0; display: flex; align-items: center; gap: 10px; }
  .ap-count { font-family: 'JetBrains Mono', monospace; font-size: 11px; font-weight: 600; color: var(--text-muted); background: var(--surface-2); border: 1px solid var(--border); border-radius: 999px; padding: 2px 9px; }

  .ap-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(100%, 300px), 1fr)); gap: 16px; }
  .ap-card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 18px; display: flex; flex-direction: column; min-width: 0; }
  .ap-row { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
  .ap-spacer { flex: 1; }

  /* Add-source form */
  .ap-form { display: flex; flex-direction: column; gap: 16px; }
  .ap-form-top { display: flex; gap: 10px; flex-wrap: wrap; }
  .ap-form-top .field { flex: 1; min-width: 0; flex-basis: 260px; }
  .ap-settings { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; align-items: end; }
  .ap-label { display: block; font-size: 12px; font-weight: 500; color: var(--text-muted); margin-bottom: 6px; }
  .ap-suffix { position: relative; }
  .ap-suffix .field { padding-right: 64px; }
  .ap-suffix span { position: absolute; right: 12px; top: 50%; transform: translateY(-50%); font-size: 12px; color: var(--text-dim); pointer-events: none; }
  .ap-range { display: flex; align-items: center; gap: 6px; }
  .ap-range .field { min-width: 0; }
  .ap-range em { font-style: normal; color: var(--text-dim); }
  .ap-error { font-size: 13px; color: #f87171; background: rgba(248, 113, 113, .08); border: 1px solid rgba(248, 113, 113, .25); border-radius: var(--radius-sm); padding: 8px 12px; word-break: break-word; }

  /* Compact buttons for dense cards */
  .ap-btn { padding: 10px 16px; font-size: 13px; border-radius: 10px; }
  .btn.ap-btn.btn-ghost { padding: 10px 12px; }
  .btn-danger:hover { color: #f87171; }

  /* Source card */
  .ap-src-value { font-weight: 600; font-size: 15px; margin: 14px 0 10px; word-break: break-word; line-height: 1.35; }
  .ap-stats { display: grid; grid-template-columns: 1fr 1fr; gap: 8px 12px; margin: 0; font-size: 12.5px; }
  .ap-stats dt { color: var(--text-dim); font-size: 11px; text-transform: uppercase; letter-spacing: .06em; margin-bottom: 2px; }
  .ap-stats dd { margin: 0; color: var(--text); font-family: 'JetBrains Mono', monospace; font-size: 12px; }
  .ap-card-foot { margin-top: auto; padding-top: 14px; display: flex; gap: 6px; align-items: center; }
  .ap-card .tgl-text { font-size: 13px; color: var(--text-muted); }
  .ap-card .tgl.on .tgl-text { color: var(--text); }
  .ap-card .tgl { gap: 8px; }

  /* Review card */
  .ap-media { position: relative; border-radius: var(--radius-sm); overflow: hidden; background: #000; aspect-ratio: 9/16; max-height: 360px; width: 100%; }
  .ap-media video { width: 100%; height: 100%; object-fit: contain; display: block; }
  .ap-placeholder { position: absolute; inset: 0; background: var(--surface-2); display: flex; flex-direction: column; gap: 10px; align-items: center; justify-content: center; font-size: 13px; color: var(--text-muted); text-align: center; padding: 20px; word-break: break-word; }
  .ap-placeholder.is-failed { color: #f87171; }
  .ap-spin { width: 22px; height: 22px; border-radius: 50%; border: 2px solid var(--border-strong); border-top-color: var(--teal); animation: ap-spin 1s linear infinite; }
  @keyframes ap-spin { to { transform: rotate(360deg); } }
  .ap-badge { position: absolute; top: 10px; left: 10px; font-family: 'JetBrains Mono', monospace; font-size: 10.5px; font-weight: 600; letter-spacing: .06em; text-transform: uppercase; padding: 4px 8px; border-radius: 6px; backdrop-filter: blur(6px); background: rgba(10, 10, 12, .7); color: var(--text-muted); }
  .ap-badge.b-pending { color: var(--yellow); }
  .ap-badge.b-ok { color: var(--teal); }
  .ap-badge.b-fail { color: #f87171; }
  .ap-badge.b-busy { color: var(--blue); }
  .ap-topic { font-weight: 600; font-size: 15px; line-height: 1.35; margin: 14px 0 6px; }
  .ap-meta { font-size: 12px; color: var(--text-muted); display: flex; flex-wrap: wrap; gap: 4px 10px; }
  .ap-meta span { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 100%; }
  .ap-viral { font-family: 'JetBrains Mono', monospace; font-size: 10.5px; font-weight: 600; text-transform: uppercase; letter-spacing: .06em; padding: 1px 7px; border-radius: 5px; background: var(--surface-3); }
  .ap-viral.v-tinggi { background: var(--teal-dim); color: var(--teal); }
  .ap-viral.v-sedang { background: var(--yellow-dim); color: var(--yellow); }
  .ap-reason { font-size: 13px; color: var(--text-muted); line-height: 1.5; margin-top: 10px; }
  .ap-actions { margin-top: auto; padding-top: 16px; display: flex; gap: 8px; }
  .ap-actions .btn:first-child { flex: 1; justify-content: center; }
  .ap-result { margin-top: auto; padding-top: 14px; font-size: 13px; color: var(--text-muted); }
  .ap-result.is-fail { color: #f87171; }
  .ap-result small { display: block; margin-top: 4px; font-size: 12px; color: var(--text-dim); word-break: break-word; }

  .ap-empty { border: 1px dashed var(--border-strong); border-radius: var(--radius); padding: 28px 16px; text-align: center; color: var(--text-muted); font-size: 13px; }
  .ap-note { font-size: 13px; color: var(--text-muted); }
  .ap-note a { color: var(--teal); }

  .ap-look { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; font-size: 13px; color: var(--text-muted); }
  .ap-chips { display: flex; flex-wrap: wrap; gap: 6px; }
  .ap-chip { font-size: 11.5px; color: var(--text-muted); background: var(--surface-2); border: 1px solid var(--border); border-radius: 999px; padding: 3px 10px; white-space: nowrap; }
  .ap-chip b { color: var(--text); font-weight: 600; }
  .ap-link { background: none; border: 0; padding: 0; font: inherit; font-size: 13px; color: var(--teal); cursor: pointer; }
  .ap-link:hover { text-decoration: underline; }

  /* Look editor */
  .ap-ed { width: 100%; max-width: 980px; max-height: calc(100vh - 48px); display: flex; flex-direction: column; background: var(--surface); border: 1px solid var(--border-strong); border-radius: var(--radius-lg); box-shadow: 0 30px 80px rgba(0,0,0,.6); overflow: hidden; }
  .ap-ed-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 18px 22px; border-bottom: 1px solid var(--border); }
  .ap-ed-head h3 { margin: 0; font-size: 17px; font-weight: 600; }
  .ap-ed-head p { margin: 2px 0 0; font-size: 12.5px; color: var(--text-muted); word-break: break-word; }
  .ap-ed-body { flex: 1; overflow-y: auto; display: grid; grid-template-columns: minmax(0, 1fr) 300px; gap: 28px; padding: 22px; }
  .ap-ed-foot { display: flex; justify-content: flex-end; gap: 10px; padding: 14px 22px; border-top: 1px solid var(--border); }
  .ap-ed-sec { padding-bottom: 22px; margin-bottom: 22px; border-bottom: 1px solid var(--border); }
  .ap-ed-sec:last-child { border-bottom: 0; margin-bottom: 0; padding-bottom: 0; }
  .ap-ed-sec h4 { margin: 0 0 14px; font-size: 12px; font-weight: 600; letter-spacing: .08em; text-transform: uppercase; color: var(--text-muted); font-family: 'JetBrains Mono', monospace; display: flex; align-items: center; justify-content: space-between; gap: 10px; }
  .ap-ed-sec .fblock { margin-bottom: 16px; }
  .ap-ed-sec .fblock:last-child { margin-bottom: 0; }
  .ap-ed-aside { position: sticky; top: 0; align-self: start; }
  .ap-ed-aside .phone-frame { max-width: 260px; }
  .ap-ed-note { font-size: 12px; color: var(--text-dim); line-height: 1.5; margin-top: 8px; }
  .ap-pv-bg { position: absolute; inset: 0; background: linear-gradient(160deg, oklch(0.36 0.07 205), oklch(0.17 0.04 255)); }
  .ap-pv-bg.gaussian::after { content: ''; position: absolute; left: 0; right: 0; top: 50%; transform: translateY(-50%); aspect-ratio: 16/9; background: linear-gradient(135deg, oklch(0.42 0.08 280), oklch(0.2 0.05 330)); outline: 1px solid rgba(255,255,255,.25); }
  .ap-row-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }

  @media (max-width: 820px) {
    .ap-ed-body { grid-template-columns: minmax(0, 1fr); }
    .ap-ed-aside { position: static; order: -1; }
    .ap-ed-aside .phone-frame { max-width: 170px; }
  }

  @media (max-width: 640px) {
    .modal-bg.ap-ed-bg { padding: 0; align-items: stretch; }
    .ap-ed { max-height: none; height: 100%; border-radius: 0; }
    .ap-ed-body { padding: 16px; }
    .ap-ed-head, .ap-ed-foot { padding: 14px 16px; }
    .ap-ed-foot .btn { flex: 1; justify-content: center; }
    .ap-wrap { padding: 28px 16px 72px; }
    .ap-head h1 { font-size: 24px; }
    .ap-form-top .btn { width: 100%; justify-content: center; }
  }
</style>
</head>
<body>
<div x-data="autopilot()" x-init="init()" x-cloak>

  <div class="topbar">
    <div class="brand">
      <div class="brand-mark"></div>
      <span class="brand-name">Clipper <span class="brand-sub">Studio</span></span>
    </div>
    <div class="topbar-nav">
      <a href="/">Studio</a>
      <a class="active" href="/autopilot">Autopilot</a>
      @include('partials.user-menu')
    </div>
  </div>

  <div class="ap-wrap">
    <div class="ap-head">
      <h1>Autopilot</h1>
      <p>
        Cari video baru dari channel atau keyword secara berkala, potong jadi klip otomatis, lalu review sebelum dikirim ke TikTok.
        Tampilan klip (layout, subtitle, gaya hook, musik) diatur per sumber lewat <b>Atur tampilan</b>; hook-nya ditulis AI untuk tiap klip.
      </p>
    </div>

    {{-- ── Sources ─────────────────────────────────────────────── --}}
    <section class="ap-section">
      <div class="ap-section-head">
        <h2>Sumber video <span class="ap-count" x-text="sources.length"></span></h2>
      </div>

      <div class="ap-card ap-form" style="margin-bottom:16px">
        <div class="ap-form-top">
          <div class="seg">
            <button :class="{ on: form.type === 'channel' }" @click="form.type = 'channel'" type="button">Channel</button>
            <button :class="{ on: form.type === 'keyword' }" @click="form.type = 'keyword'" type="button">Keyword</button>
          </div>
          <input class="field" x-model="form.value" @keydown.enter="form.value.trim() && addSource()"
                 :placeholder="form.type === 'channel' ? '@namachannel atau https://youtube.com/@namachannel' : 'contoh: podcast bisnis indonesia'">
          <button class="btn" @click="addSource()" :disabled="!form.value.trim()" type="button">Tambah sumber</button>
        </div>
        <div class="ap-settings">
          <label>
            <span class="ap-label">Cek tiap</span>
            <div class="ap-suffix"><input class="field" type="number" min="1" max="168" x-model.number="form.interval_hours"><span>jam</span></div>
          </label>
          <label>
            <span class="ap-label">Maks per cek</span>
            <div class="ap-suffix"><input class="field" type="number" min="1" max="5" x-model.number="form.max_per_run"><span>video</span></div>
          </label>
          <div>
            <span class="ap-label">Durasi video (menit)</span>
            <div class="ap-range">
              <input class="field" type="number" min="1" x-model.number="form.min_minutes" aria-label="Durasi minimum">
              <em>–</em>
              <input class="field" type="number" min="1" x-model.number="form.max_minutes" aria-label="Durasi maksimum">
            </div>
          </div>
        </div>
        <div class="ap-look">
          <span>Tampilan clip:</span>
          <div class="ap-chips"><template x-for="c in lookChips(form.settings)" :key="c"><span class="ap-chip" x-html="c"></span></template></div>
          <button class="ap-link" type="button" @click="openEditor(null)">Atur tampilan</button>
        </div>
        <div x-show="formError" class="ap-error" x-text="formError"></div>
      </div>

      <div x-show="sources.length === 0" class="ap-empty">Belum ada sumber. Tambahkan channel atau keyword di atas.</div>
      <div class="ap-grid">
        <template x-for="s in sources" :key="s.id">
          <div class="ap-card">
            <div class="ap-row" style="justify-content:space-between">
              <span class="status-pill" x-text="s.type === 'channel' ? 'Channel' : 'Keyword'"></span>
              <button class="tgl" :class="{ on: s.enabled }" @click="toggleSource(s)" type="button">
                <span class="tgl-text" x-text="s.enabled ? 'Aktif' : 'Nonaktif'"></span><span class="tgl-track"></span>
              </button>
            </div>
            <div class="ap-src-value" x-text="s.value"></div>
            <dl class="ap-stats">
              <div><dt>Jadwal</dt><dd x-text="'tiap ' + s.interval_hours + ' jam'"></dd></div>
              <div><dt>Maks</dt><dd x-text="s.max_per_run + ' video/cek'"></dd></div>
              <div><dt>Durasi</dt><dd x-text="s.min_minutes + '–' + s.max_minutes + ' mnt'"></dd></div>
              <div><dt>Diproses</dt><dd x-text="s.videos_found + ' video'"></dd></div>
              <div style="grid-column:1/-1"><dt>Cek terakhir</dt><dd x-text="s.last_run_at ? new Date(s.last_run_at).toLocaleString('id-ID', { dateStyle: 'medium', timeStyle: 'short' }) : 'belum pernah'"></dd></div>
            </dl>
            <div class="ap-chips" style="margin-top:12px"><template x-for="c in lookChips(s.settings)" :key="c"><span class="ap-chip" x-html="c"></span></template></div>
            <div x-show="s.last_error" class="ap-error" style="margin-top:12px;font-size:12px" x-text="s.last_error"></div>
            <div class="ap-card-foot">
              <button class="btn btn-secondary ap-btn" @click="runSource(s)" type="button">Cek sekarang</button>
              <button class="btn btn-ghost ap-btn" @click="openEditor(s)" type="button">Atur tampilan</button>
              <span class="ap-spacer"></span>
              <button class="btn btn-ghost ap-btn btn-danger" @click="deleteSource(s)" type="button">Hapus</button>
            </div>
          </div>
        </template>
      </div>
    </section>

    {{-- ── Review queue ────────────────────────────────────────── --}}
    <section class="ap-section">
      <div class="ap-section-head">
        <h2>Antrian review <span class="ap-count" x-text="clips.filter(c => c.review_status === 'pending').length + ' menunggu'"></span></h2>
        <div class="ap-note">
          <template x-if="!tiktokConfigured">
            <span>Isi kredensial TikTok di <code>.env</code> untuk bisa approve ke TikTok.</span>
          </template>
          <template x-if="tiktokConfigured && accounts.length === 0">
            <span>Belum ada akun TikTok. <a href="/tiktok/connect">Hubungkan akun</a></span>
          </template>
          <template x-if="accounts.length > 0">
            <div class="ap-row">
              <span>Kirim ke</span>
              <div class="seg">
                <template x-for="a in accounts" :key="a.id">
                  <button :class="{ on: accountId === a.id }" @click="accountId = a.id" type="button" x-text="a.display_name || ('Akun #' + a.id)"></button>
                </template>
              </div>
            </div>
          </template>
        </div>
      </div>

      <div x-show="clips.length === 0" class="ap-empty">Belum ada klip dari autopilot.</div>
      <div class="ap-grid">
        <template x-for="c in clips" :key="c.id">
          <div class="ap-card">
            <div class="ap-media">
              <template x-if="c.preview_url">
                <video :src="c.preview_url" controls preload="metadata"></video>
              </template>
              <template x-if="!c.preview_url">
                <div class="ap-placeholder" :class="{ 'is-failed': c.status === 'failed' }">
                  <div class="ap-spin" x-show="c.status !== 'failed' && c.review_status !== 'rejected'"></div>
                  <span x-text="c.status === 'failed' ? ('Render gagal: ' + (c.error_msg || '')) : (c.review_status === 'rejected' ? 'Ditolak' : 'Sedang dirender…')"></span>
                </div>
              </template>
              <span class="ap-badge" :class="clipBadge(c).cls" x-text="clipBadge(c).label"></span>
            </div>

            <div class="ap-topic" x-text="c.topic"></div>
            <div class="ap-meta">
              <span x-text="c.video_title || c.youtube_url" :title="c.video_title || c.youtube_url"></span>
              <span x-text="c.duration + ' dtk'"></span>
              <span class="ap-viral" :class="'v-' + c.viral_potential" x-text="'viral ' + c.viral_potential"></span>
            </div>
            <div class="ap-reason" x-show="c.reason" x-text="c.reason"></div>
            <div class="ap-reason" x-show="c.music_credit" style="font-size:12px">🎵 <span x-text="c.music_credit"></span>
              <button class="ap-link" style="font-size:12px;margin-left:4px" type="button" @click="navigator.clipboard?.writeText(c.music_credit)">salin kredit</button></div>

            <div class="ap-actions" x-show="c.review_status === 'pending'">
              <button class="btn ap-btn" type="button" @click="approve(c)"
                      :disabled="c.status !== 'done' || !accountId">Approve → TikTok</button>
              <button class="btn btn-ghost ap-btn btn-danger" type="button" @click="reject(c)">Reject</button>
            </div>
            <div class="ap-result" x-show="c.review_status !== 'pending'" :class="{ 'is-fail': c.tiktok_status === 'failed' }">
              <span x-text="reviewLabel(c)"></span>
              <small x-show="c.tiktok_status === 'failed' && c.tiktok_error" x-text="c.tiktok_error"></small>
            </div>
            <div x-show="c.actionError" class="ap-error" style="margin-top:10px;font-size:12px" x-text="c.actionError"></div>
          </div>
        </template>
      </div>
    </section>
  </div>

  {{-- ── Look editor (new source or existing one) ───────────────── --}}
  <div class="modal-bg ap-ed-bg" x-show="ed.open" x-cloak @keydown.escape.window="ed.open = false" @click.self="ed.open = false">
    <div class="ap-ed" x-show="ed.open" @click.stop>
      <div class="ap-ed-head">
        <div>
          <h3>Tampilan clip autopilot</h3>
          <p x-text="ed.source ? ed.source.value + ' · berlaku untuk clip berikutnya' : 'Untuk sumber baru'"></p>
        </div>
        <button class="hist-close" type="button" @click="ed.open = false" aria-label="Tutup">✕</button>
      </div>

      <template x-if="ed.s">
      <div class="ap-ed-body">
        <div>
          <section class="ap-ed-sec">
            <h4>Video</h4>
            <div class="fblock">
              <div class="fblock-title" style="margin-bottom:10px">Layout</div>
              <div class="seg">
                <button :class="{ on: ed.s.layout_type === 'reframe' }" @click="ed.s.layout_type = 'reframe'" type="button">Reframe wajah</button>
                <button :class="{ on: ed.s.layout_type === 'gaussian' }" @click="ed.s.layout_type = 'gaussian'" type="button">Gaussian blur</button>
              </div>
            </div>
            <div class="fblock">
              <div class="fblock-title" style="margin-bottom:10px">Clip per video</div>
              <div class="seg">
                <template x-for="n in [1, 3, 5]" :key="n">
                  <button :class="{ on: ed.s.clip_count === n }" @click="ed.s.clip_count = n" type="button" x-text="n + ' clip'"></button>
                </template>
              </div>
            </div>
            <div class="fblock">
              <div class="fblock-title" style="margin-bottom:10px">Durasi clip</div>
              <div class="seg">
                <button :class="{ on: ed.s.duration_mode === 'auto' }" @click="ed.s.duration_mode = 'auto'" type="button">Otomatis (AI)</button>
                <button :class="{ on: ed.s.duration_mode === 'manual' }" @click="ed.s.duration_mode = 'manual'" type="button">Atur sendiri</button>
              </div>
              <div class="ap-row-2" x-show="ed.s.duration_mode === 'manual'" style="margin-top:10px">
                <label><span class="ap-label">Minimal</span><div class="ap-suffix"><input class="field" type="number" min="5" max="300" x-model.number="ed.s.min_duration"><span>detik</span></div></label>
                <label><span class="ap-label">Maksimal</span><div class="ap-suffix"><input class="field" type="number" min="10" max="600" x-model.number="ed.s.max_duration"><span>detik</span></div></label>
              </div>
            </div>
          </section>

          <section class="ap-ed-sec">
            <h4>Hook pembuka
              <button class="tgl" :class="{ on: ed.s.hook.enabled }" @click="ed.s.hook.enabled = !ed.s.hook.enabled; pvTab = 'hook'" type="button"><span class="tgl-track"></span></button>
            </h4>
            <div class="settings-group" :class="{ off: !ed.s.hook.enabled }">
              <div class="fblock">
                <div class="fblock-title" style="margin-bottom:10px">Gaya</div>
                <div class="hook-styles">
                  <button class="hook-style" :class="{ on: ed.s.hook.background_style === 'full' }" @click="ed.s.hook.background_style = 'full'; pvTab = 'hook'" type="button">
                    <span class="hook-style-demo hs-full"><i>Label</i><i>TikTok</i></span><span class="hook-style-name">Label</span>
                  </button>
                  <button class="hook-style" :class="{ on: ed.s.hook.background_style === 'semi' }" @click="ed.s.hook.background_style = 'semi'; pvTab = 'hook'" type="button">
                    <span class="hook-style-demo hs-semi">Kotak<br>gelap</span><span class="hook-style-name">Kotak</span>
                  </button>
                  <button class="hook-style" :class="{ on: ed.s.hook.background_style === 'none' }" @click="ed.s.hook.background_style = 'none'; pvTab = 'hook'" type="button">
                    <span class="hook-style-demo hs-none">Teks<br>outline</span><span class="hook-style-name">Outline</span>
                  </button>
                </div>
              </div>
              <div class="fblock">
                <div class="fblock-title" style="margin-bottom:10px" x-text="ed.s.hook.background_style === 'full' ? 'Warna label' : 'Warna teks'"></div>
                <div class="swatches">
                  <template x-for="c in COLORS" :key="c">
                    <button class="swatch" :class="{ on: ed.s.hook.text_color === c }" :style="'background:' + c" @click="ed.s.hook.text_color = c; pvTab = 'hook'" type="button"></button>
                  </template>
                </div>
              </div>
              <div class="fblock">
                <div class="fblock-title" style="margin-bottom:10px">Posisi</div>
                <div class="seg">
                  <button :class="{ on: ed.s.hook.position === 'top' }" @click="ed.s.hook.position = 'top'; pvTab = 'hook'" type="button">Atas</button>
                  <button :class="{ on: ed.s.hook.position === 'center' }" @click="ed.s.hook.position = 'center'; pvTab = 'hook'" type="button">Tengah</button>
                  <button :class="{ on: ed.s.hook.position === 'bottom' }" @click="ed.s.hook.position = 'bottom'; pvTab = 'hook'" type="button">Bawah</button>
                </div>
              </div>
              <div class="fblock">
                <div class="fblock-title" style="margin-bottom:10px">Durasi tampil</div>
                <div class="sld-wrap">
                  <input class="sld" type="range" min="2" max="5" step="0.5" x-model.number="ed.s.hook.duration_seconds" />
                  <span class="sld-val" x-text="ed.s.hook.duration_seconds + 's'"></span>
                </div>
              </div>
              <div class="ap-ed-note">Teks hook ditulis AI untuk tiap clip (3–7 kata, kata kunci disorot dengan warna highlight subtitle).</div>
            </div>
          </section>

          <section class="ap-ed-sec">
            <h4>Subtitle
              <button class="tgl" :class="{ on: ed.s.subtitle.enabled }" @click="ed.s.subtitle.enabled = !ed.s.subtitle.enabled; pvTab = 'sub'" type="button"><span class="tgl-track"></span></button>
            </h4>
            <div class="settings-group" :class="{ off: !ed.s.subtitle.enabled }">
              <div class="ap-row-2">
                <div class="fblock">
                  <div class="fblock-title" style="margin-bottom:10px">Font</div>
                  <select class="select" x-model="ed.s.subtitle.font_family" @change="pvTab = 'sub'">
                    <template x-for="f in FONTS" :key="f"><option :value="f" x-text="f" :selected="f === ed.s.subtitle.font_family"></option></template>
                  </select>
                </div>
                <div class="fblock">
                  <div class="fblock-title" style="margin-bottom:10px">Ukuran</div>
                  <div class="sld-wrap">
                    <input class="sld" type="range" min="24" max="72" x-model.number="ed.s.subtitle.font_size" @input="pvTab = 'sub'" />
                    <span class="sld-val" x-text="ed.s.subtitle.font_size + 'px'"></span>
                  </div>
                </div>
              </div>
              <div class="fblock">
                <div class="fblock-title" style="margin-bottom:10px">Warna teks</div>
                <div class="swatches">
                  <template x-for="c in COLORS" :key="c">
                    <button class="swatch" :class="{ on: ed.s.subtitle.text_color === c }" :style="'background:' + c" @click="ed.s.subtitle.text_color = c; pvTab = 'sub'" type="button"></button>
                  </template>
                </div>
              </div>
              <div class="fblock">
                <div class="fblock-title" style="margin-bottom:10px">Warna highlight <span style="color:var(--text-dim);font-weight:400;font-size:12px">· kata aktif &amp; sorotan hook</span></div>
                <div class="swatches">
                  <template x-for="c in HL_COLORS" :key="c">
                    <button class="swatch" :class="{ on: ed.s.subtitle.highlight_color === c }" :style="'background:' + c" @click="ed.s.subtitle.highlight_color = c" type="button"></button>
                  </template>
                </div>
              </div>
              <div class="ap-row-2">
                <div class="fblock">
                  <div class="fblock-title" style="margin-bottom:10px">Posisi</div>
                  <div class="seg">
                    <button :class="{ on: ed.s.subtitle.position_y == null && ed.s.subtitle.position === 'top' }" @click="ed.s.subtitle.position = 'top'; ed.s.subtitle.position_y = null; pvTab = 'sub'" type="button">Atas</button>
                    <button :class="{ on: ed.s.subtitle.position_y == null && ed.s.subtitle.position === 'center' }" @click="ed.s.subtitle.position = 'center'; ed.s.subtitle.position_y = null; pvTab = 'sub'" type="button">Tengah</button>
                    <button :class="{ on: ed.s.subtitle.position_y == null && ed.s.subtitle.position === 'bottom' }" @click="ed.s.subtitle.position = 'bottom'; ed.s.subtitle.position_y = null; pvTab = 'sub'" type="button">Bawah</button>
                    <button class="on" x-show="ed.s.subtitle.position_y != null" @click="ed.s.subtitle.position_y = null" type="button" title="Kembali ke preset"><span x-text="'Kustom · ' + ed.s.subtitle.position_y + '%'"></span> ✕</button>
                  </div>
                  <div class="ap-ed-note">Atau seret subtitle di preview.</div>
                </div>
                <div class="fblock">
                  <div class="fblock-title" style="margin-bottom:10px">Background</div>
                  <div class="seg">
                    <button :class="{ on: ed.s.subtitle.background_style === 'none' }" @click="ed.s.subtitle.background_style = 'none'; pvTab = 'sub'" type="button">Tanpa</button>
                    <button :class="{ on: ed.s.subtitle.background_style === 'semi' }" @click="ed.s.subtitle.background_style = 'semi'; pvTab = 'sub'" type="button">Semi</button>
                    <button :class="{ on: ed.s.subtitle.background_style === 'full' }" @click="ed.s.subtitle.background_style = 'full'; pvTab = 'sub'" type="button">Penuh</button>
                  </div>
                </div>
              </div>
            </div>
          </section>

          <section class="ap-ed-sec">
            <h4>Musik latar
              <button class="tgl" :class="{ on: ed.s.music.enabled }" @click="ed.s.music.enabled = !ed.s.music.enabled" type="button"><span class="tgl-track"></span></button>
            </h4>
            <div class="settings-group" :class="{ off: !ed.s.music.enabled }">
              <div class="fblock">
                <div class="fblock-title" style="margin-bottom:10px">Mood</div>
                <div class="seg" style="flex-wrap:wrap">
                  <button :class="{ on: !ed.s.music.mood }" @click="ed.s.music.mood = null" type="button">Otomatis (AI)</button>
                  <template x-for="m in MOODS" :key="m.id">
                    <button :class="{ on: ed.s.music.mood === m.id }" @click="ed.s.music.mood = m.id" type="button" x-text="m.label"></button>
                  </template>
                </div>
              </div>
              <div class="fblock">
                <div class="fblock-title" style="margin-bottom:10px">Volume</div>
                <div class="sld-wrap">
                  <input class="sld" type="range" min="5" max="100" step="1" x-model.number="ed.s.music.volume" />
                  <span class="sld-val" x-text="ed.s.music.volume + '%'"></span>
                </div>
              </div>
              <div class="ap-ed-note">Lagu diambil dari <a href="/music" style="color:var(--teal)">library musik</a> sesuai mood; kredit CC BY otomatis masuk caption.</div>
              <div class="music-warn" x-show="ed.s.music.enabled && musicTotal === 0">Library musik masih kosong — clip akan dibuat <b>tanpa musik</b>. <a href="/music">Upload lagu dulu →</a></div>
            </div>
          </section>
        </div>

        {{-- Live preview --}}
        <div class="ap-ed-aside">
          <div class="seg" style="display:flex;margin-bottom:12px">
            <button :class="{ on: pvTab === 'hook' }" @click="pvTab = 'hook'" type="button">Hook</button>
            <button :class="{ on: pvTab === 'sub' }" @click="pvTab = 'sub'" type="button">Subtitle</button>
          </div>
          <div class="phone-frame">
            <div class="phone-notch"></div>
            <div class="ap-pv-bg" :class="ed.s.layout_type"></div>
            <template x-for="k in (pvTab === 'hook' && ed.s.hook.enabled ? [pvKey()] : [])" :key="k">
              <div class="pv-hook">
                <div class="pv-hook-block" :class="'hk-' + ed.s.hook.background_style" :style="pvHookStyle()">
                  <template x-for="(line, i) in pvHookLines()" :key="i">
                    <span class="pv-hook-line"><template x-for="(run, j) in line" :key="j"><span><span x-show="j > 0"> </span><span :class="{ 'hk-em': run.em }" x-text="run.t"></span></span></template></span>
                  </template>
                </div>
              </div>
            </template>
            <div class="pv-guides" x-show="subDragging">
              <div class="pv-guide-mid"></div>
              <div class="pv-guide-ui"><span>tertutup UI TikTok</span></div>
            </div>
            <div x-show="pvTab === 'sub' && ed.s.subtitle.enabled" :style="ClipPreview.subStyle(ed.s.subtitle.position, ed.s.subtitle.position_y)"
                 class="pv-sub-drag" :class="{ dragging: subDragging }" title="Seret untuk memindahkan subtitle"
                 @pointerdown="subDragging = true; ClipPreview.dragSubtitle($event, y => ed.s.subtitle.position_y = y, () => subDragging = false)">
              <div :style="ClipPreview.subBoxStyle({ font: ed.s.subtitle.font_family, size: ed.s.subtitle.font_size, bg: ed.s.subtitle.background_style })">
                <span :style="'color:' + ed.s.subtitle.highlight_color">Setiap</span>
                <span :style="'color:' + ed.s.subtitle.text_color">momen adalah kesempatan</span>
              </div>
            </div>
            <div class="pv-hook-tag" x-show="(pvTab === 'hook' && !ed.s.hook.enabled) || (pvTab === 'sub' && !ed.s.subtitle.enabled)">NONAKTIF</div>
          </div>
          <div class="ap-ed-note" style="text-align:center">Contoh teks — hook asli ditulis AI per clip.</div>
        </div>
      </div>
      </template>

      <div class="ap-ed-foot">
        <div x-show="ed.error" class="ap-error" style="margin-right:auto;font-size:12px" x-text="ed.error"></div>
        <button class="btn btn-secondary ap-btn" type="button" @click="ed.open = false">Batal</button>
        <button class="btn ap-btn" type="button" @click="saveEditor()" :disabled="ed.saving" x-text="ed.saving ? 'Menyimpan…' : (ed.source ? 'Simpan' : 'Pakai tampilan ini')"></button>
      </div>
    </div>
  </div>
</div>

<script>
function autopilot() {
  const headers = () => ({
    'Content-Type': 'application/json',
    'Accept': 'application/json',
    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
  });
  const api = async (url, opts = {}) => {
    const res = await fetch(url, { headers: headers(), ...opts });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(data.message || ('HTTP ' + res.status));
    return data;
  };

  return {
    sources: [], clips: [], accounts: [], accountId: null, tiktokConfigured: false,
    form: { type: 'channel', value: '', interval_hours: 6, max_per_run: 1, min_minutes: 5, max_minutes: 120, settings: null },
    formError: null, timer: null,
    ed: { open: false, source: null, s: null, saving: false, error: null },
    pvTab: 'hook', fontsTick: 0, subDragging: false, musicTotal: null,
    SAMPLE_HOOK: 'Rahasia nikah *60 tahun*',
    FONTS: ['Arial', 'Roboto', 'Montserrat', 'Oswald', 'Bebas Neue'],
    COLORS: ['#ffffff', '#facc15', '#2dd4bf', '#a78bfa', '#60a5fa', '#fb7185', '#f97316', '#000000'],
    HL_COLORS: ['#facc15', '#2dd4bf', '#a78bfa', '#60a5fa', '#fb7185', '#22c55e', '#ffffff', '#f97316'],
    MOODS: [
      { id: 'energetic', label: 'Energik' }, { id: 'chill', label: 'Santai' }, { id: 'inspiring', label: 'Inspiratif' },
      { id: 'dramatic', label: 'Dramatis' }, { id: 'funny', label: 'Lucu' }, { id: 'sad', label: 'Sedih' },
    ],

    async init() {
      document.fonts?.ready.then(() => this.fontsTick++);
      await Promise.all([this.loadSources(), this.loadClips(), this.loadAccounts(), this.loadDefaults(),
        api('/api/music').then(d => { this.musicTotal = d.total; }).catch(() => {})]);
      // Rendering and uploads progress in the background; keep the page fresh
      this.timer = setInterval(() => { this.loadClips(); this.loadSources(); }, 10000);
    },
    async loadDefaults() {
      try { this.form.settings = await api('/api/autopilot/defaults'); } catch (e) { console.error(e); }
    },
    // Short summary of a source's clip look, shown as chips
    lookChips(s) {
      if (!s) return [];
      // Rendered with x-html (for the <b>): escape the values, never trust stored data
      const esc = v => String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
      const hook = { full: 'Label', semi: 'Kotak', none: 'Outline' }[s.hook.background_style] || '';
      return [
        `<b>${s.layout_type === 'gaussian' ? 'Gaussian' : 'Reframe'}</b>`,
        `${esc(s.clip_count)} clip`,
        s.hook.enabled ? `Hook <b>${esc(hook)}</b>` : 'Tanpa hook',
        s.subtitle.enabled ? `Subtitle <b>${esc(s.subtitle.font_family)}</b>` : 'Tanpa subtitle',
        ...(s.music.enabled ? ['Musik'] : []),
      ];
    },
    openEditor(source) {
      const base = source ? source.settings : this.form.settings;
      if (!base) return;
      this.ed = { open: true, source, s: JSON.parse(JSON.stringify(base)), saving: false, error: null };
      this.pvTab = 'hook';
    },
    async saveEditor() {
      const s = this.ed.s;
      if (s.duration_mode === 'manual' && !(s.min_duration >= 5 && s.max_duration > s.min_duration)) {
        this.ed.error = 'Durasi maksimal harus lebih besar dari minimal (min. 5 detik).';
        return;
      }
      if (!this.ed.source) { this.form.settings = s; this.ed.open = false; return; }
      this.ed.saving = true; this.ed.error = null;
      try {
        await api(`/api/autopilot/sources/${this.ed.source.id}`, { method: 'PATCH', body: JSON.stringify({ settings: s }) });
        await this.loadSources();
        this.ed.open = false;
      } catch (e) {
        this.ed.error = e.message;
      } finally {
        this.ed.saving = false;
      }
    },
    pvHookLines() { return ClipPreview.hookLayout(this.SAMPLE_HOOK, this.ed.s.subtitle.font_family, this.fontsTick).lines; },
    pvHookStyle() {
      const h = this.ed.s.hook;
      return ClipPreview.hookStyle(this.SAMPLE_HOOK, {
        family: this.ed.s.subtitle.font_family, color: h.text_color, accent: this.ed.s.subtitle.highlight_color,
        bg: h.background_style, pos: h.position, tick: this.fontsTick,
      });
    },
    // Changing the hook look replays the pop animation
    pvKey() { const h = this.ed.s.hook; return [h.background_style, h.text_color, h.position, this.ed.s.subtitle.font_family].join('|'); },
    async loadSources() { try { this.sources = await api('/api/autopilot/sources'); } catch (e) { console.error(e); } },
    async loadClips() {
      try {
        const errors = Object.fromEntries(this.clips.filter(c => c.actionError).map(c => [c.id, c.actionError]));
        this.clips = (await api('/api/autopilot/review')).map(c => ({ ...c, actionError: errors[c.id] || null }));
      } catch (e) { console.error(e); }
    },
    async loadAccounts() {
      try {
        const data = await api('/api/tiktok/accounts');
        this.tiktokConfigured = data.configured;
        this.accounts = data.accounts || [];
        this.accountId = this.accounts[0]?.id ?? null;
      } catch (e) { console.error(e); }
    },
    async addSource() {
      this.formError = null;
      try {
        const { settings, ...body } = this.form;
        await api('/api/autopilot/sources', { method: 'POST', body: JSON.stringify(settings ? { ...body, settings } : body) });
        this.form.value = '';
        await this.loadSources();
      } catch (e) { this.formError = e.message; }
    },
    async toggleSource(s) {
      await api(`/api/autopilot/sources/${s.id}`, { method: 'PATCH', body: JSON.stringify({ enabled: !s.enabled }) });
      s.enabled = !s.enabled;
    },
    async runSource(s) {
      await api(`/api/autopilot/sources/${s.id}/run`, { method: 'POST' });
      await this.loadSources();
    },
    async deleteSource(s) {
      if (!confirm('Hapus sumber "' + s.value + '"? Klip yang sudah dibuat tetap ada.')) return;
      await api(`/api/autopilot/sources/${s.id}`, { method: 'DELETE' });
      await this.loadSources();
    },
    async approve(c) {
      c.actionError = null;
      try {
        await api(`/api/autopilot/review/${c.id}/approve`, { method: 'POST', body: JSON.stringify({ account_id: this.accountId }) });
        await this.loadClips();
      } catch (e) { c.actionError = e.message; }
    },
    async reject(c) {
      c.actionError = null;
      try {
        await api(`/api/autopilot/review/${c.id}/reject`, { method: 'POST' });
        await this.loadClips();
      } catch (e) { c.actionError = e.message; }
    },
    clipBadge(c) {
      if (c.review_status === 'rejected') return { label: 'Ditolak', cls: '' };
      if (c.status === 'failed') return { label: 'Gagal render', cls: 'b-fail' };
      if (c.review_status === 'pending') {
        return c.status === 'done' ? { label: 'Perlu review', cls: 'b-pending' } : { label: 'Rendering', cls: 'b-busy' };
      }
      if (c.tiktok_status === 'failed') return { label: 'Upload gagal', cls: 'b-fail' };
      if (['inbox', 'published'].includes(c.tiktok_status)) return { label: 'Terkirim', cls: 'b-ok' };
      return { label: 'Disetujui', cls: 'b-busy' };
    },
    reviewLabel(c) {
      if (c.review_status === 'rejected') return '✕ Ditolak';
      return {
        queued: 'Disetujui · menunggu antrian upload…',
        uploading: 'Disetujui · mengupload ke TikTok…',
        processing: 'Disetujui · TikTok sedang memproses…',
        inbox: '✓ Terkirim ke inbox TikTok',
        published: '✓ Sudah diposting',
        failed: 'Upload gagal',
      }[c.tiktok_status] || 'Disetujui';
    },
  };
}
</script>
</body>
</html>
