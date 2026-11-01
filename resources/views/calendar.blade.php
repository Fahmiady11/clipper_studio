<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>Jadwal publish — Clipper Studio</title>
@include('partials.favicon')
<meta name="csrf-token" content="{{ csrf_token() }}" />
@include('partials.head')
@vite('resources/css/studio.css')
@include('partials.session-guard')
<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
<style>
  /* Reading: scheduling page for solo creators on a phone. ENERGY 2 / RHYTHM 2 / MOTION 1.
     Lime marks only today and the one primary action. Times are Geist Mono (DESIGN.md: timecodes). */
  .cal-wrap { max-width: 1120px; margin: 0 auto; padding: 40px 24px 96px; }
  .cal-head h1 { font-size: 28px; font-weight: 700; letter-spacing: -.02em; margin: 0 0 8px; }
  .cal-head p { color: var(--text-muted); margin: 0; font-size: 14px; line-height: 1.6; max-width: 720px; }
  .cal-tz { font-family: var(--font-mono); font-size: 12px; color: var(--text-muted); }

  .cal-layout { display: grid; grid-template-columns: minmax(0, 1fr); gap: 20px; margin-top: 28px; align-items: start; }
  .cal-card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 12px; min-width: 0; }

  .cal-bar { display: flex; align-items: center; gap: 6px; padding: 4px 4px 12px; }
  .cal-bar h2 { flex: 1; margin: 0; font-size: 17px; font-weight: 600; text-transform: capitalize; }
  .cal-nav { display: inline-flex; align-items: center; justify-content: center; min-width: 44px; min-height: 44px; border-radius: 10px; border: 1px solid var(--border); background: var(--surface-2); color: var(--text); font-size: 18px; cursor: pointer; font-family: inherit; }
  .cal-nav:hover { background: var(--surface-3); }
  .cal-nav.txt { font-size: 13px; font-weight: 500; padding: 0 14px; }

  .cal-grid { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); gap: 4px; }
  .cal-dow { font-size: 11px; font-weight: 600; color: var(--text-muted); text-align: center; padding: 6px 0; }
  .cal-day { position: relative; display: flex; flex-direction: column; align-items: stretch; gap: 4px; min-width: 0; min-height: 48px; padding: 6px 4px; border-radius: 10px; border: 1px solid var(--border); background: var(--surface-2); color: var(--text); cursor: pointer; font-family: inherit; text-align: left; }
  .cal-day:hover { background: var(--surface-3); }
  .cal-day.out { background: transparent; color: var(--text-muted); }
  .cal-day.sel { box-shadow: inset 0 0 0 2px var(--accent-ink); background: var(--surface-3); }
  .cal-num { font-family: var(--font-mono); font-size: 13px; font-weight: 500; align-self: center; min-width: 26px; text-align: center; line-height: 26px; border-radius: 7px; }
  .cal-day.today .cal-num { background: var(--accent); color: var(--on-accent); font-weight: 700; }
  .cal-count { align-self: center; font-family: var(--font-mono); font-size: 11px; font-weight: 600; color: var(--info); line-height: 1; }
  .cal-count.bad { color: var(--danger); }
  .cal-evs { display: none; flex-direction: column; gap: 3px; min-width: 0; }
  .cal-ev { font-family: var(--font-mono); font-size: 10.5px; line-height: 1.3; padding: 2px 5px; border-radius: 5px; background: var(--surface); border: 1px solid var(--border); color: var(--text); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .cal-ev.bad { color: var(--danger); }
  .cal-more { font-size: 10.5px; color: var(--text-muted); padding-left: 2px; }
  .cal-load { font-size: 12px; color: var(--text-muted); padding: 10px 4px 0; min-height: 28px; }

  .cal-panel { display: flex; flex-direction: column; gap: 16px; }
  .cal-panel-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
  .cal-panel-head h2 { margin: 0; font-size: 17px; font-weight: 600; }
  .cal-panel-head small { display: block; font-size: 12px; color: var(--text-muted); font-weight: 400; margin-top: 3px; }
  .cal-btn { min-height: 44px; padding: 10px 16px; font-size: 13.5px; border-radius: 10px; }

  .cal-list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 10px; }
  .cal-item { background: var(--surface-2); border: 1px solid var(--border); border-radius: 12px; padding: 12px; display: grid; grid-template-columns: auto minmax(0, 1fr); gap: 4px 12px; }
  .cal-time { font-family: var(--font-mono); font-size: 18px; font-weight: 600; letter-spacing: -.02em; grid-row: 1 / span 2; }
  .cal-topic { font-size: 13.5px; font-weight: 600; line-height: 1.35; overflow: hidden; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; word-break: break-word; }
  .cal-meta { font-size: 12px; color: var(--text-muted); display: flex; gap: 6px 10px; flex-wrap: wrap; align-items: center; }
  .cal-state { font-weight: 600; color: var(--info); }
  .cal-state.good { color: var(--accent-ink); }
  .cal-state.bad { color: var(--danger); }
  .cal-err { grid-column: 1 / -1; font-size: 12.5px; color: var(--danger); background: var(--danger-soft); border-radius: 8px; padding: 8px 10px; word-break: break-word; }
  .cal-acts { grid-column: 1 / -1; display: flex; gap: 8px; flex-wrap: wrap; align-items: center; margin-top: 6px; }
  .cal-acts .btn, .cal-acts a.btn { min-height: 44px; padding: 8px 14px; font-size: 13px; border-radius: 10px; }
  .cal-acts a.cal-link { font-size: 13px; color: var(--accent-ink); }
  .cal-edit { grid-column: 1 / -1; display: flex; gap: 8px; flex-wrap: wrap; align-items: center; margin-top: 6px; }
  .cal-edit .field { flex: 1 1 200px; width: auto; }

  .cal-note { border: 1px dashed var(--border-strong); border-radius: var(--radius); padding: 22px 16px; text-align: center; color: var(--text-muted); font-size: 13.5px; line-height: 1.55; }
  .cal-note b { color: var(--text); display: block; margin-bottom: 4px; }
  .cal-note.bad { border-style: solid; border-color: var(--danger); background: var(--danger-soft); color: var(--text); }
  .cal-note .btn { margin-top: 12px; }
  .cal-note a { color: var(--accent-ink); }

  .cal-form { display: flex; flex-direction: column; gap: 14px; }
  .cal-contents { display: contents; }
  .cal-form h3 { margin: 0; font-size: 15px; font-weight: 600; }
  .cal-label { display: block; font-size: 12px; font-weight: 500; color: var(--text-muted); margin-bottom: 6px; }
  .cal-form .seg { display: flex; flex-wrap: wrap; }
  .cal-form .seg button { min-height: 44px; flex: 1 1 auto; }
  .cal-form .seg button[disabled] { opacity: .5; cursor: not-allowed; }
  .cal-check { display: flex; align-items: center; gap: 10px; min-height: 44px; font-size: 13.5px; color: var(--text); }
  .cal-check input { width: 18px; height: 18px; accent-color: var(--accent-ink); }
  .cal-hint { font-size: 12px; color: var(--text-muted); line-height: 1.5; }
  .cal-hint.warn { color: var(--warn); }
  .cal-error { font-size: 13px; color: var(--danger); background: var(--danger-soft); border: 1px solid var(--danger); border-radius: var(--radius-sm); padding: 8px 12px; word-break: break-word; }
  .cal-form-acts { display: flex; gap: 10px; flex-wrap: wrap; }

  @media (min-width: 640px) {
    .cal-card { padding: 16px; }
    .cal-day { min-height: 92px; padding: 8px 6px; }
    .cal-count { display: none; }
    .cal-evs { display: flex; }
    .cal-num { align-self: flex-start; }
  }
  @media (min-width: 900px) {
    .cal-layout { grid-template-columns: minmax(0, 1fr) 400px; }
    .cal-panel { position: sticky; top: 76px; }
  }
  @media (max-width: 639px) {
    .cal-wrap { padding: 28px 16px 72px; }
    .cal-head h1 { font-size: 24px; }
    .cal-grid { gap: 3px; }
    .cal-bar h2 { font-size: 16px; }
    .cal-acts .btn { flex: 1 1 auto; }
    .cal-form-acts .btn { flex: 1; }
  }
</style>
</head>
<body>
<div x-data="scheduleCalendar()" x-init="init()" x-cloak>

  @include('partials.topbar', ['active' => 'calendar'])

  <div class="cal-wrap">
    <div class="cal-head">
      <h1>Jadwal publish</h1>
      <p>Tentukan tanggal, jam, dan platform untuk clip yang sudah dirender. Worker mengirimnya ke TikTok, YouTube Shorts, Facebook, Instagram, atau Threads saat waktunya tiba, jadi browser tidak perlu terbuka.
        Jam mengikuti zona waktu browser kamu: <span class="cal-tz" x-text="tz"></span>.</p>
    </div>

    <div class="cal-layout">

      {{-- Month --}}
      <section class="cal-card" aria-label="Kalender bulanan">
        <div class="cal-bar">
          <button type="button" class="cal-nav" @click="shiftMonth(-1)" aria-label="Bulan sebelumnya">&lsaquo;</button>
          <h2 x-text="monthTitle" aria-live="polite"></h2>
          <button type="button" class="cal-nav txt" @click="goToday()">Hari ini</button>
          <button type="button" class="cal-nav" @click="shiftMonth(1)" aria-label="Bulan berikutnya">&rsaquo;</button>
        </div>

        <div class="cal-grid">
          <template x-for="d in DOW" :key="d">
            <div class="cal-dow" x-text="d"></div>
          </template>
          <template x-for="day in days" :key="day.key">
            <button type="button" class="cal-day"
                    :class="{ out: !day.inMonth, today: day.isToday, sel: day.key === selected }"
                    :aria-pressed="day.key === selected ? 'true' : 'false'"
                    :aria-current="day.isToday ? 'date' : null"
                    :aria-label="dayLabel(day)"
                    @click="selectDay(day)">
              <span class="cal-num" x-text="day.date.getDate()"></span>
              <span class="cal-count" :class="{ bad: day.posts.some(isFailed) }" x-show="day.posts.length > 0" x-text="day.posts.length"></span>
              <span class="cal-evs">
                <template x-for="p in day.posts.slice(0, 2)" :key="p.id">
                  <span class="cal-ev" :class="{ bad: isFailed(p) }" :title="PLATFORM[p.platform].name + ' ' + timeOf(p)" x-text="timeOf(p) + ' ' + PLATFORM[p.platform].short"></span>
                </template>
                <span class="cal-more" x-show="day.posts.length > 2" x-text="'+' + (day.posts.length - 2) + ' lagi'"></span>
              </span>
            </button>
          </template>
        </div>

        <div class="cal-load" role="status" aria-live="polite">
          <span x-show="loading">Memuat jadwal…</span>
        </div>
      </section>

      {{-- Selected day --}}
      <section class="cal-card cal-panel" x-ref="panel" aria-label="Jadwal di tanggal terpilih">
        <div class="cal-panel-head">
          <h2>
            <span x-text="selectedTitle"></span>
            <small x-text="selectedPosts.length ? (selectedPosts.length + ' jadwal') : ''"></small>
          </h2>
          <button type="button" class="btn cal-btn" x-show="!form.open" @click="openForm()">Jadwalkan clip</button>
        </div>

        {{-- Error: schedule could not load --}}
        <div class="cal-note bad" x-show="loadError" role="alert">
          <b>Jadwal gagal dimuat.</b>
          <span x-text="loadError"></span>
          <div><button type="button" class="btn btn-secondary cal-btn" @click="load()">Coba lagi</button></div>
        </div>

        {{-- Create form --}}
        <form class="cal-form" x-show="form.open" @submit.prevent="submit()" @keydown.escape="closeForm()">
          <h3>Jadwalkan clip</h3>

          <div class="cal-note" x-show="clipsLoaded && clips.length === 0">
            <b>Belum ada clip yang selesai dirender.</b>
            Render clip di <a href="/">Studio</a> dulu, lalu kembali ke sini untuk menjadwalkannya.
          </div>

          <div x-show="clips.length > 0" class="cal-contents">
            <div>
              <label class="cal-label" for="cal-clip">Clip</label>
              <select id="cal-clip" class="select" x-model="form.clipId" @change="fillFromClip()" style="min-height:44px">
                <template x-for="c in clips" :key="c.id">
                  <option :value="c.id" x-text="clipOption(c)" :selected="c.id === form.clipId"></option>
                </template>
              </select>
              <div class="cal-hint warn" style="margin-top:8px" x-show="$data[form.platform]?.needsDefault">Akun utama platform ini sudah diputus. Jadwal ini memakai akun yang dipilih di atas; <a href="/settings/accounts" style="color:var(--accent-ink)">pilih akun utama</a> untuk autopilot.</div>
            </div>

            <div>
              <span class="cal-label" id="cal-platform-label">Platform</span>
              <div class="seg" role="group" aria-labelledby="cal-platform-label">
                <template x-for="(info, id) in PLATFORM" :key="id">
                  <button type="button" :class="{ on: form.platform === id }" :disabled="!$data[id].accounts.length" @click="pickPlatform(id)" x-text="info.name"></button>
                </template>
              </div>
              <div class="cal-hint" style="margin-top:8px" x-show="missingPlatforms.length">
                <template x-for="m in missingPlatforms" :key="m.id">
                  <span><template x-if="m.configured"><a :href="m.connect" style="color:var(--accent-ink)" x-text="'Hubungkan ' + m.noun"></a></template><template x-if="!m.configured"><span x-text="m.site + ' belum dikonfigurasi di server'"></span></template>. </span>
                </template>
              </div>
            </div>

            <div x-show="form.platform && (currentAccounts.length > 1 || $data[form.platform]?.needsDefault)">
              <label class="cal-label" for="cal-account" x-text="(({ youtube: 'Channel', facebook: 'Page', threads: 'Profil' })[form.platform] || 'Akun') + ' untuk jadwal ini'"></label>
              <select id="cal-account" class="select" x-model.number="form.accountId" style="min-height:44px">
                <template x-for="a in currentAccounts" :key="a.id">
                  <option :value="a.id" x-text="accountName(a) + (a.id === $data[form.platform].defaultId ? ' (utama)' : '')" :selected="a.id === form.accountId"></option>
                </template>
              </select>
            </div>

            <div>
              <label class="cal-label" for="cal-when">Tanggal dan jam</label>
              <input id="cal-when" class="field" type="datetime-local" x-model="form.when" required style="font-family:var(--font-mono)">
            </div>

            <template x-if="form.platform === 'youtube'">
              <div class="cal-form">
                <div>
                  <label class="cal-label" for="cal-yt-title">Judul</label>
                  <input id="cal-yt-title" class="field" maxlength="100" x-model="form.ytTitle">
                  <div class="field-count" x-text="form.ytTitle.length + '/100'"></div>
                </div>
                <div>
                  <label class="cal-label" for="cal-yt-desc">Deskripsi</label>
                  <textarea id="cal-yt-desc" class="field" maxlength="5000" x-model="form.ytDescription"></textarea>
                </div>
                <div>
                  <label class="cal-label" for="cal-yt-priv">Privasi</label>
                  <select id="cal-yt-priv" class="select" x-model="form.ytPrivacy" style="min-height:44px">
                    <option value="private">Pribadi</option>
                    <option value="unlisted">Tidak publik</option>
                    <option value="public">Publik</option>
                  </select>
                  <div class="cal-hint warn" style="margin-top:8px">Selama app belum lolos audit YouTube API, video tetap tersimpan sebagai Pribadi. Ubah ke Publik di YouTube Studio.</div>
                </div>
              </div>
            </template>

            {{-- Facebook, Instagram, Threads: one text field with the platform's own limit --}}
            <template x-if="META[form.platform]">
              <div class="cal-form">
                <div class="cal-error" x-show="$data[form.platform].blocked" role="alert" x-text="$data[form.platform].blocked"></div>
                <div>
                  <label class="cal-label" for="cal-meta-text" x-text="META[form.platform].label"></label>
                  <textarea id="cal-meta-text" class="field" x-model="form.metaText[form.platform]"></textarea>
                  <div class="field-count" :style="metaProblem ? 'color:var(--danger)' : ''" x-text="metaCount"></div>
                </div>
                <div class="cal-hint" x-text="META[form.platform].hint"></div>
                <div class="cal-hint warn" x-show="metaProblem" x-text="metaProblem"></div>
                <div class="cal-hint warn" x-show="selectedClip && selectedClip.seconds > META[form.platform].maxSeconds"
                     x-text="'Clip ini ' + (selectedClip && selectedClip.seconds) + ' detik, lebih dari batas ' + META[form.platform].maxSeconds + ' detik ' + PLATFORM[form.platform].name + '.'"></div>
              </div>
            </template>

            <template x-if="form.platform === 'tiktok'">
              <div class="cal-form">
                <div>
                  <span class="cal-label" id="cal-tt-mode-label">Cara kirim</span>
                  <div class="seg" role="group" aria-labelledby="cal-tt-mode-label">
                    <button type="button" :class="{ on: form.ttMode === 'inbox' }" @click="form.ttMode = 'inbox'">Draft (inbox)</button>
                    <button type="button" :class="{ on: form.ttMode === 'direct' }" @click="form.ttMode = 'direct'">Posting langsung</button>
                  </div>
                  <div class="cal-hint" style="margin-top:8px" x-show="form.ttMode === 'inbox'">Draft masuk ke inbox TikTok tanpa caption. Kamu selesaikan posting-nya di aplikasi TikTok.</div>
                </div>
                <div x-show="form.ttMode === 'direct'" class="cal-contents">
                  <div>
                    <label class="cal-label" for="cal-tt-title">Caption</label>
                    <textarea id="cal-tt-title" class="field" maxlength="2200" x-model="form.ttTitle"></textarea>
                  </div>
                  <label class="cal-check"><input type="checkbox" x-model="form.ttComment"> Izinkan komentar</label>
                  <label class="cal-check"><input type="checkbox" x-model="form.ttDuet"> Izinkan duet</label>
                  <label class="cal-check"><input type="checkbox" x-model="form.ttStitch"> Izinkan stitch</label>
                  <div class="cal-hint warn">App TikTok belum lolos audit, jadi posting langsung sementara hanya privat (Hanya saya).</div>
                </div>
              </div>
            </template>

            <div class="cal-error" x-show="formError" role="alert" x-text="formError"></div>

            <div class="cal-form-acts">
              <button type="submit" class="btn cal-btn" :disabled="saving || !canSubmit" x-text="saving ? 'Menyimpan…' : 'Jadwalkan'"></button>
              <button type="button" class="btn btn-secondary cal-btn" @click="closeForm()">Batal</button>
            </div>
          </div>

          <div class="cal-form-acts" x-show="clips.length === 0">
            <button type="button" class="btn btn-secondary cal-btn" @click="closeForm()">Tutup</button>
          </div>
        </form>

        {{-- Day list --}}
        <ul class="cal-list" x-show="!form.open && selectedPosts.length > 0">
          <template x-for="p in selectedPosts" :key="p.id">
            <li class="cal-item">
              <div class="cal-time" x-text="timeOf(p)"></div>
              <div style="min-width:0">
                <div class="cal-topic" x-text="p.clip_topic || 'Clip tidak ditemukan'"></div>
                <div class="cal-meta">
                  <span x-text="PLATFORM[p.platform].name + (p.account_label ? ' · ' + p.account_label : '')"></span>
                  <span class="cal-state" :class="stateClass(p)" x-text="stateLabel(p)"></span>
                </div>
              </div>
              <div class="cal-err" x-show="p.error" x-text="p.error"></div>
              <div class="cal-hint" style="grid-column:1/-1" x-show="p.note" x-text="p.note"></div>

              <template x-if="editing && editing.id === p.id">
                <div class="cal-edit">
                  <label class="cal-label" :for="'cal-edit-' + p.id" style="flex-basis:100%;margin:0">Jam baru</label>
                  <input :id="'cal-edit-' + p.id" class="field" type="datetime-local" x-model="editing.when" style="font-family:var(--font-mono)">
                  <button type="button" class="btn cal-btn" @click="saveEdit()" :disabled="saving">Simpan</button>
                  <button type="button" class="btn btn-secondary cal-btn" @click="editing = null; editError = ''">Batal</button>
                  <div class="cal-error" style="flex-basis:100%" x-show="editError" role="alert" x-text="editError"></div>
                </div>
              </template>

              <div class="cal-acts" x-show="!(editing && editing.id === p.id)">
                <template x-if="p.status === 'scheduled' || p.status === 'failed'">
                  <button type="button" class="btn btn-secondary" @click="startEdit(p)" x-text="p.status === 'failed' ? 'Jadwalkan ulang' : 'Ubah jam'"></button>
                </template>
                <template x-if="(p.status === 'scheduled' || p.status === 'failed') && confirmingId !== p.id">
                  <button type="button" class="btn btn-ghost" @click="confirmingId = p.id" x-text="p.status === 'failed' ? 'Hapus' : 'Batalkan'"></button>
                </template>
                <template x-if="confirmingId === p.id">
                  <span class="cal-meta">
                    <span x-text="p.status === 'failed' ? 'Hapus jadwal ini?' : 'Batalkan jadwal ini?'"></span>
                    <button type="button" class="btn btn-secondary" @click="remove(p)">Ya</button>
                    <button type="button" class="btn btn-ghost" @click="confirmingId = null">Tidak</button>
                  </span>
                </template>
                <a class="cal-link" x-show="p.url" :href="p.url" target="_blank" rel="noopener" x-text="'Buka di ' + PLATFORM[p.platform].site"></a>
                <a class="cal-link" x-show="p.reconnect_url" :href="p.reconnect_url">Hubungkan ulang</a>
              </div>
            </li>
          </template>
        </ul>

        {{-- Empty day --}}
        <div class="cal-note" x-show="!form.open && !loading && !loadError && selectedPosts.length === 0">
          <b>Belum ada jadwal di tanggal ini.</b>
          Tekan <i>Jadwalkan clip</i> untuk memilih clip, platform, dan jam tayang.
        </div>
      </section>
    </div>
  </div>
</div>

<script>
function scheduleCalendar() {
  const pad = n => String(n).padStart(2, '0');
  const dayKey = d => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
  const toLocalInput = d => `${dayKey(d)}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
  const csrf = () => document.querySelector('meta[name=csrf-token]').content;
  const headers = { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() };
  const MIN_LEAD_MS = 2 * 60 * 1000;

  // Server errors arrive as {message} or Laravel's {errors: {field: [msg]}}
  const errorText = (data, fallback) => (data && data.errors ? Object.values(data.errors)[0][0] : (data && data.message)) || fallback;

  return {
    DOW: ['Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab', 'Min'],
    PLATFORM: {
      tiktok: { name: 'TikTok', short: 'TT', site: 'TikTok' },
      youtube: { name: 'YouTube Shorts', short: 'YT', site: 'YouTube' },
      facebook: { name: 'Reels Facebook', short: 'FB', site: 'Facebook' },
      instagram: { name: 'Reels Instagram', short: 'IG', site: 'Instagram' },
      threads: { name: 'Threads', short: 'TH', site: 'Threads' },
    },
    CONNECT: {
      tiktok: { noun: 'akun TikTok', url: '/settings/accounts#tiktok' },
      youtube: { noun: 'channel YouTube', url: '/settings/accounts#youtube' },
      facebook: { noun: 'Page Facebook', url: '/settings/accounts#facebook' },
      instagram: { noun: 'Instagram', url: '/settings/accounts#instagram' },
      threads: { noun: 'Threads', url: '/settings/accounts#threads' },
    },
    // Text limits as each API states them; Threads counts emoji by their UTF-8 bytes
    META: {
      facebook: { label: 'Deskripsi', max: 5000, maxSeconds: 90, hint: 'Reels di Page selalu publik. Syarat Facebook: video vertikal 9:16, 3 sampai 90 detik.' },
      instagram: { label: 'Caption', max: 2200, hashtags: 30, maxSeconds: 900, hint: 'Reels Instagram tampil di tab Reels dan feed. Maksimal 2200 karakter dan 30 hashtag.' },
      threads: { label: 'Teks', max: 500, bytes: true, maxSeconds: 300, hint: 'Threads hanya menjadikan satu hashtag sebagai topik. Video maksimal 5 menit.' },
    },
    tz: Intl.DateTimeFormat().resolvedOptions().timeZone || 'lokal',

    month: new Date(new Date().getFullYear(), new Date().getMonth(), 1),
    selected: dayKey(new Date()),
    posts: [], loading: true, loadError: '', timer: null,

    clips: [], clipsLoaded: false,
    tiktok: { configured: false, accounts: [] },
    youtube: { configured: false, accounts: [] },
    facebook: { configured: false, accounts: [], blocked: null },
    instagram: { configured: false, accounts: [], blocked: null },
    threads: { configured: false, accounts: [], blocked: null },

    form: { open: false, clipId: '', platform: '', accountId: null, when: '', ytTitle: '', ytDescription: '', ytTags: [], ytPrivacy: 'private', metaText: { facebook: '', instagram: '', threads: '' }, ttMode: 'inbox', ttTitle: '', ttComment: false, ttDuet: false, ttStitch: false },
    formError: '', saving: false,
    editing: null, editError: '', confirmingId: null,

    async init() {
      await Promise.all([this.load(), this.loadClips(), this.loadAccounts()]);
      const wanted = new URLSearchParams(location.search).get('clip');
      if (wanted && this.clips.some(c => c.id === wanted)) this.openForm(wanted);
      // Statuses move on their own (queued, uploading, published); refresh while the tab is visible
      this.timer = setInterval(() => { if (!document.hidden && !this.saving) this.load(true); }, 30000);
    },

    // ── calendar math ──────────────────────────────────────────────────────
    get gridStart() {
      const offset = (this.month.getDay() + 6) % 7; // weeks start on Monday
      return new Date(this.month.getFullYear(), this.month.getMonth(), 1 - offset);
    },
    get days() {
      const todayKey = dayKey(new Date());
      const byDay = {};
      this.posts.forEach(p => (byDay[dayKey(new Date(p.scheduled_at))] ||= []).push(p));
      return Array.from({ length: 42 }, (_, i) => {
        const date = new Date(this.gridStart.getFullYear(), this.gridStart.getMonth(), this.gridStart.getDate() + i);
        const key = dayKey(date);
        return { key, date, inMonth: date.getMonth() === this.month.getMonth(), isToday: key === todayKey, posts: byDay[key] || [] };
      });
    },
    get monthTitle() { return this.month.toLocaleDateString('id-ID', { month: 'long', year: 'numeric' }); },
    get selectedPosts() { return (this.days.find(d => d.key === this.selected) || { posts: this.posts.filter(p => dayKey(new Date(p.scheduled_at)) === this.selected) }).posts; },
    get selectedTitle() {
      const [y, m, d] = this.selected.split('-').map(Number);
      const label = new Date(y, m - 1, d).toLocaleDateString('id-ID', { weekday: 'long', day: 'numeric', month: 'long' });
      return this.selected === dayKey(new Date()) ? label + ' (hari ini)' : label;
    },
    dayLabel(day) {
      const base = day.date.toLocaleDateString('id-ID', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
      return day.posts.length ? `${base}, ${day.posts.length} jadwal` : base;
    },
    timeOf(p) { return new Date(p.scheduled_at).toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit', hour12: false }); },

    shiftMonth(by) {
      this.month = new Date(this.month.getFullYear(), this.month.getMonth() + by, 1);
      this.selected = dayKey(this.month);
      this.load();
    },
    goToday() {
      const now = new Date();
      this.month = new Date(now.getFullYear(), now.getMonth(), 1);
      this.selected = dayKey(now);
      this.load();
    },
    selectDay(day) {
      this.selected = day.key;
      this.editing = null; this.confirmingId = null;
      // Stacked layout: the day's list sits below the grid, so bring it into view
      if (innerWidth < 900) this.$nextTick(() => this.$refs.panel.scrollIntoView({ block: 'start' }));
      if (!day.inMonth) { this.month = new Date(day.date.getFullYear(), day.date.getMonth(), 1); this.load(); }
      if (this.form.open) this.form.when = this.defaultWhen();
    },

    // ── data ───────────────────────────────────────────────────────────────
    async load(silent = false) {
      if (!silent) { this.loading = true; this.loadError = ''; }
      const from = this.gridStart;
      const to = new Date(from.getFullYear(), from.getMonth(), from.getDate() + 42);
      try {
        const res = await fetch(`/api/schedule?from=${encodeURIComponent(from.toISOString())}&to=${encodeURIComponent(to.toISOString())}`, { headers: { 'Accept': 'application/json' } });
        if (!res.ok) throw new Error(errorText(await res.json().catch(() => null), 'Server menjawab ' + res.status + '.'));
        this.posts = (await res.json()).posts;
        this.loadError = '';
      } catch (e) {
        if (!silent) this.loadError = e.message || 'Koneksi gagal.';
      } finally {
        this.loading = false;
      }
    },
    async loadClips() {
      try {
        const res = await fetch('/api/schedule/clips', { headers: { 'Accept': 'application/json' } });
        if (res.ok) this.clips = (await res.json()).clips;
      } catch (e) { console.error('loadClips', e); }
      this.clipsLoaded = true;
    },
    async loadAccounts() {
      const get = async url => { try { const r = await fetch(url, { headers: { 'Accept': 'application/json' } }); return r.ok ? await r.json() : null; } catch { return null; } };
      const meta = ['facebook', 'instagram', 'threads'];
      const [tt, yt, ...rest] = await Promise.all([get('/api/tiktok/accounts'), get('/api/youtube/accounts'), ...meta.map(p => get('/api/meta/targets/' + p))]);
      if (tt) this.tiktok = { configured: tt.configured, accounts: tt.accounts || [], defaultId: tt.default_id, needsDefault: tt.needs_default };
      if (yt) this.youtube = { configured: yt.configured, accounts: yt.accounts || [], defaultId: yt.default_id, needsDefault: yt.needs_default };
      meta.forEach((p, i) => { if (rest[i]) this[p] = { configured: rest[i].configured, accounts: rest[i].accounts || [], blocked: rest[i].blocked, defaultId: rest[i].default_id, needsDefault: rest[i].needs_default }; });
    },

    // ── status text (always words, never color alone) ──────────────────────
    isFailed(p) { return p.status === 'failed' || (p.status === 'queued' && p.delivery === 'failed'); },
    stateLabel(p) {
      if (p.status === 'scheduled') return 'Terjadwal';
      if (p.status === 'publishing') return 'Sedang dikirim';
      if (p.status === 'failed') return 'Gagal dikirim';
      const name = this.PLATFORM[p.platform].name;
      return ({
        queued: 'Antre upload', uploading: 'Mengupload', processing: 'Diproses ' + name,
        inbox: 'Masuk draft TikTok', failed: 'Upload gagal',
        published: ({ youtube: p.privacy === 'private' ? 'Di YouTube, privat' : 'Sudah di YouTube' })[p.platform] || 'Tayang di ' + this.PLATFORM[p.platform].site,
      })[p.delivery] || 'Dikirim ke ' + name;
    },
    stateClass(p) {
      if (this.isFailed(p)) return 'bad';
      return ['published', 'inbox'].includes(p.delivery) ? 'good' : '';
    },

    // ── create ─────────────────────────────────────────────────────────────
    get currentAccounts() { return (this[this.form.platform] || { accounts: [] }).accounts; },
    get selectedClip() { return this.clips.find(c => c.id === this.form.clipId) || null; },
    accountName(a) { return a.display_name || a.title || (a.username ? (a.name || a.username) + ' (@' + a.username + ')' : a.name) || ('Akun #' + a.id); },
    get missingPlatforms() {
      return Object.keys(this.PLATFORM).filter(p => !this[p].accounts.length)
        .map(p => ({ id: p, site: this.PLATFORM[p].site, configured: this[p].configured, noun: this.CONNECT[p].noun, connect: this.CONNECT[p].url }));
    },
    get metaCount() {
      const rule = this.META[this.form.platform];
      const text = this.form.metaText[this.form.platform] || '';
      return (rule.bytes ? new TextEncoder().encode(text).length : text.length) + '/' + rule.max;
    },
    get metaProblem() {
      const rule = this.META[this.form.platform];
      if (!rule) return '';
      const text = this.form.metaText[this.form.platform] || '';
      const length = rule.bytes ? new TextEncoder().encode(text).length : text.length;
      if (length > rule.max) return 'Terlalu panjang untuk ' + this.PLATFORM[this.form.platform].site + ', maksimal ' + rule.max + (rule.bytes ? ' (emoji dihitung lebih dari satu).' : ' karakter.');
      const tags = (text.match(/(^|\s)#[\p{L}\p{N}_]+/gu) || []).length;
      if (rule.hashtags && tags > rule.hashtags) return 'Maksimal ' + rule.hashtags + ' hashtag, teks ini punya ' + tags + '.';
      return '';
    },
    clipOption(c) { return `${c.topic} · ${c.project_title || 'tanpa judul'} · ${c.seconds} dtk`; },
    get canSubmit() {
      const f = this.form;
      if (!f.clipId || !f.platform || !f.accountId || !f.when) return false;
      if (f.platform === 'youtube') return !!f.ytTitle.trim() && !/[<>]/.test(f.ytTitle);
      if (this.META[f.platform]) return !this.metaProblem && !this[f.platform].blocked;
      return true;
    },
    defaultWhen() {
      const [y, m, d] = this.selected.split('-').map(Number);
      const now = new Date();
      let when = new Date(y, m - 1, d, 19, 0);
      if (when.getTime() < now.getTime() + MIN_LEAD_MS) {
        when = new Date(now.getFullYear(), now.getMonth(), now.getDate(), now.getHours() + 1, 0);
        if (dayKey(when) !== this.selected) when = new Date(y, m - 1, d, 23, 59);
      }
      return toLocalInput(when);
    },
    openForm(clipId = null) {
      this.editing = null; this.formError = '';
      const f = this.form;
      f.open = true;
      f.clipId = clipId || f.clipId || this.clips[0]?.id || '';
      f.platform = f.platform || Object.keys(this.PLATFORM).find(p => this[p].accounts.length) || '';
      this.pickPlatform(f.platform);
      f.when = this.defaultWhen();
      this.fillFromClip();
    },
    // Cut to a byte budget at a word, like the server does for autopilot posts
    fitBytes(text, max) {
      const enc = new TextEncoder();
      if (enc.encode(text).length <= max) return text;
      let out = '';
      for (const word of text.split(/(\s+)/)) {
        if (enc.encode(out + word + '…').length > max) break;
        out += word;
      }
      return out.trimEnd() + '…';
    },
    closeForm() { this.form.open = false; this.formError = ''; },
    // Starts at the platform's default account (Akun terhubung); the select overrides it for this post
    pickPlatform(platform) {
      this.form.platform = platform;
      const list = this.currentAccounts;
      this.form.accountId = (this[platform] || {}).defaultId ?? list[0]?.id ?? null;
    },
    // Defaults from the clip's own topic and AI caption; the user edits from there
    fillFromClip() {
      const c = this.clips.find(x => x.id === this.form.clipId);
      if (!c) return;
      const f = this.form;
      const tags = (c.hashtags || []).map(h => String(h).replace(/^#/, '')).filter(Boolean);
      const body = ((c.caption || '') + '\n\n' + (c.hashtags || []).join(' ')).trim();
      f.ytTitle = String(c.topic || '').replace(/[<>]/g, '').slice(0, 100);
      f.ytDescription = body.slice(0, 5000);
      f.ytTags = tags.slice(0, 30);
      // Defaults within each platform's limit: Instagram keeps 30 hashtags, Threads one
      f.metaText.facebook = body.slice(0, 5000);
      f.metaText.instagram = ((c.caption || '') + '\n\n' + (c.hashtags || []).slice(0, 30).join(' ')).trim().slice(0, 2200);
      f.metaText.threads = this.fitBytes(((c.caption || '') + '\n\n' + ((c.hashtags || [])[0] || '')).trim(), 500);
      f.ttTitle = body.slice(0, 2200);
    },
    async submit() {
      const f = this.form;
      this.formError = '';
      const when = new Date(f.when);
      if (isNaN(when) || when.getTime() < Date.now() + MIN_LEAD_MS) { this.formError = 'Pilih waktu minimal 2 menit dari sekarang.'; return; }

      const body = { clip_id: f.clipId, platform: f.platform, account_id: f.accountId, scheduled_at: when.toISOString() };
      if (f.platform === 'youtube') Object.assign(body, { title: f.ytTitle.trim(), description: f.ytDescription, privacy: f.ytPrivacy, tags: f.ytTags });
      else if (this.META[f.platform]) Object.assign(body, { description: f.metaText[f.platform] });
      else Object.assign(body, { mode: f.ttMode, title: f.ttMode === 'direct' ? f.ttTitle : '', allow_comment: f.ttComment, allow_duet: f.ttDuet, allow_stitch: f.ttStitch });

      this.saving = true;
      try {
        const res = await fetch('/api/schedule', { method: 'POST', headers: { ...headers, 'X-CSRF-TOKEN': csrf() }, body: JSON.stringify(body) });
        const data = await res.json().catch(() => null);
        if (!res.ok) { this.formError = errorText(data, 'Gagal menyimpan jadwal.'); return; }
        this.closeForm();
        this.selected = dayKey(when);
        this.month = new Date(when.getFullYear(), when.getMonth(), 1);
        await this.load();
      } catch (e) {
        this.formError = 'Koneksi gagal. Coba lagi.';
      } finally {
        this.saving = false;
      }
    },

    // ── change / cancel ────────────────────────────────────────────────────
    startEdit(p) {
      this.closeForm(); this.confirmingId = null; this.editError = '';
      this.editing = { id: p.id, when: toLocalInput(new Date(Math.max(new Date(p.scheduled_at).getTime(), Date.now() + 5 * 60 * 1000))) };
    },
    async saveEdit() {
      const when = new Date(this.editing.when);
      this.editError = '';
      if (isNaN(when) || when.getTime() < Date.now() + MIN_LEAD_MS) { this.editError = 'Pilih waktu minimal 2 menit dari sekarang.'; return; }
      this.saving = true;
      try {
        const res = await fetch(`/api/schedule/${this.editing.id}`, { method: 'PATCH', headers: { ...headers, 'X-CSRF-TOKEN': csrf() }, body: JSON.stringify({ scheduled_at: when.toISOString() }) });
        const data = await res.json().catch(() => null);
        if (!res.ok) { this.editError = errorText(data, 'Gagal mengubah jadwal.'); return; }
        this.editing = null;
        this.selected = dayKey(when);
        this.month = new Date(when.getFullYear(), when.getMonth(), 1);
        await this.load();
      } catch (e) {
        this.editError = 'Koneksi gagal. Coba lagi.';
      } finally {
        this.saving = false;
      }
    },
    async remove(p) {
      this.confirmingId = null;
      try {
        const res = await fetch(`/api/schedule/${p.id}`, { method: 'DELETE', headers: { ...headers, 'X-CSRF-TOKEN': csrf() } });
        if (!res.ok && res.status !== 404) { this.loadError = errorText(await res.json().catch(() => null), 'Gagal membatalkan jadwal.'); return; }
        this.loadError = '';
        await this.load(true);
      } catch (e) {
        this.loadError = 'Koneksi gagal. Coba lagi.';
      }
    },
  };
}
</script>
</body>
</html>
