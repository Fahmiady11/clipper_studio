<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>Library Musik — Clipper Studio</title>
@include('partials.favicon')
<meta name="csrf-token" content="{{ csrf_token() }}" />
@include('partials.head')
@vite('resources/css/studio.css')
@include('partials.session-guard')
<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
<style>
  .mu-wrap { max-width: 1000px; margin: 0 auto; padding: 40px 24px 96px; }
  .mu-head h1 { font-size: 28px; font-weight: 700; letter-spacing: -.02em; margin: 0 0 8px; }
  .mu-head p { color: var(--text-muted); margin: 0; font-size: 14px; line-height: 1.6; max-width: 720px; }
  .mu-card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 20px; }
  .mu-sec { margin-top: 36px; }
  .mu-sec h2 { font-size: 17px; font-weight: 600; margin: 0 0 14px; display: flex; align-items: center; gap: 10px; }
  .mu-count { font-family: var(--font-mono); font-size: 11px; font-weight: 600; color: var(--text-muted); background: var(--surface-2); border: 1px solid var(--border); border-radius: 999px; padding: 2px 9px; }

  .mu-guide { margin-top: 24px; display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; }
  .mu-step { background: var(--surface); border: 1px solid var(--border); border-radius: 12px; padding: 14px; font-size: 13px; color: var(--text-muted); line-height: 1.5; }
  .mu-step b { display: block; color: var(--text); font-size: 13.5px; margin-bottom: 4px; }
  .mu-step i { font-style: normal; font-family: var(--font-mono); font-size: 11px; color: var(--accent-ink); }

  .mu-drop { display: block; border: 1.5px dashed var(--border-strong); border-radius: 12px; padding: 28px 16px; text-align: center; color: var(--text-muted); font-size: 14px; cursor: pointer; transition: border-color .15s, background .15s; }
  .mu-drop.over, .mu-drop:hover { border-color: var(--accent-ink); background: var(--accent-soft); color: var(--text); }
  .mu-drop b { color: var(--text); }
  .mu-bulk { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px; margin: 16px 0; }
  .mu-label { display: block; font-size: 12px; font-weight: 500; color: var(--text-muted); margin-bottom: 6px; }

  .mu-queue { display: flex; flex-direction: column; gap: 10px; }
  .mu-item { display: grid; grid-template-columns: minmax(0, 1fr) 150px 32px; gap: 10px; align-items: start; padding: 12px; background: var(--surface-2); border: 1px solid var(--border); border-radius: 12px; }
  .mu-item-name { font-size: 13px; font-weight: 600; margin-bottom: 8px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .mu-item-name small { font-weight: 400; color: var(--text-dim); margin-left: 6px; }
  .mu-item .field { padding: 9px 12px; font-size: 13px; }
  .mu-item select.select { padding: 9px 12px; font-size: 13px; }
  .mu-x { width: 32px; height: 32px; border-radius: 8px; border: 1px solid var(--border); background: none; color: var(--text-dim); cursor: pointer; }
  .mu-x:hover { color: var(--danger); border-color: var(--danger); }
  .mu-status { font-size: 12px; margin-top: 6px; color: var(--text-muted); }
  .mu-status.ok { color: var(--accent-ink); }
  .mu-status.err { color: var(--danger); }
  .mu-bar { height: 3px; border-radius: 999px; background: var(--surface-3); overflow: hidden; margin-top: 8px; }
  .mu-bar > div { height: 100%; background: var(--accent); transition: width .2s; }
  .mu-actions { display: flex; gap: 10px; align-items: center; justify-content: flex-end; margin-top: 16px; flex-wrap: wrap; }

  .mu-moods { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 14px; }
  .mu-track { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 10px 14px; align-items: center; padding: 14px; border-bottom: 1px solid var(--border); }
  .mu-track:last-child { border-bottom: 0; }
  .mu-track-name { font-size: 13.5px; font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .mu-track-meta { font-size: 12px; color: var(--text-muted); margin-top: 3px; display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
  .mu-lic { font-family: var(--font-mono); font-size: 10px; font-weight: 600; letter-spacing: .06em; text-transform: uppercase; padding: 2px 7px; border-radius: 5px; background: var(--surface-3); color: var(--text-muted); }
  .mu-lic.cc { background: var(--warn-soft); color: var(--warn); }
  .mu-lic.free { background: var(--accent-soft); color: var(--accent-ink); }
  .mu-lic.none { background: rgba(248, 113, 113, .12); color: var(--danger); }
  .mu-track audio { width: 100%; height: 34px; grid-column: 1 / -1; }
  .mu-track-tools { display: flex; gap: 6px; align-items: center; }
  .mu-track-tools select.select { padding: 7px 30px 7px 10px; font-size: 12.5px; width: auto; background-position: right 10px center; }
  .mu-edit { grid-column: 1 / -1; display: flex; gap: 8px; flex-wrap: wrap; }
  .mu-edit .field { flex: 1; min-width: 200px; padding: 9px 12px; font-size: 13px; }
  .mu-empty { border: 1px dashed var(--border-strong); border-radius: var(--radius); padding: 28px 16px; text-align: center; color: var(--text-muted); font-size: 13px; }
  .ap-btn { padding: 10px 16px; font-size: 13px; border-radius: 10px; }

  @media (max-width: 640px) {
    .mu-wrap { padding: 28px 16px 72px; }
    .mu-head h1 { font-size: 24px; }
    .mu-item { grid-template-columns: minmax(0, 1fr) 32px; }
    .mu-item > .mu-item-mood { grid-column: 1 / 2; grid-row: 2; }
    .mu-track { grid-template-columns: minmax(0, 1fr); }
    .mu-actions .btn { flex: 1; justify-content: center; }
  }
</style>
</head>
<body>
<div x-data="musicLibrary()" x-init="init()" x-cloak>

  @include('partials.topbar', ['active' => 'music'])

  <div class="mu-wrap">
    <div class="mu-head">
      <h1>Library musik</h1>
      <p>Musik latar untuk clip (studio & autopilot), dikelompokkan per mood. Pakai hanya lagu yang boleh dipublikasikan di TikTok:
        dari YouTube Audio Library hanya yang berlisensi <b>Creative Commons (CC BY)</b> — lagu berlisensi standar YouTube hanya boleh dipakai di YouTube.
        Kredit lagu CC BY otomatis ditambahkan ke caption clip.</p>
    </div>

    <div class="mu-guide">
      <div class="mu-step"><i>01</i><b>Buka Audio Library</b>YouTube Studio → Audio Library. Filter <b style="display:inline">Attribution required</b> (Creative Commons) dan mood.</div>
      <div class="mu-step"><i>02</i><b>Unduh & salin kredit</b>Unduh lagunya, lalu salin teks atribusi dari ikon lisensi (contoh: "Song by Artist is licensed under CC BY 4.0…").</div>
      <div class="mu-step"><i>03</i><b>Upload di sini</b>Pilih banyak file sekaligus, atur mood, tempel kredit tiap lagu, lalu upload.</div>
    </div>

    {{-- ── Upload ─────────────────────────────────────────────── --}}
    <section class="mu-sec">
      <h2>Upload lagu</h2>
      <div class="mu-card">
        <label class="mu-drop" :class="{ over: dragging }"
               @dragover.prevent="dragging = true" @dragleave.prevent="dragging = false"
               @drop.prevent="dragging = false; addFiles($event.dataTransfer.files)">
          <input type="file" multiple accept=".mp3,.m4a,.aac,.wav,.ogg,audio/*" style="display:none" @change="addFiles($event.target.files); $event.target.value = ''">
          <b>Pilih file</b> atau seret ke sini · mp3, m4a, aac, wav, ogg · maks 50 MB per file
        </label>

        <template x-if="queue.length > 0">
          <div>
            <div class="mu-bulk">
              <div>
                <span class="mu-label">Mood untuk semua</span>
                <select class="select" x-model="bulkMood" @change="queue.forEach(q => { if (q.state !== 'done') q.mood = bulkMood })">
                  <template x-for="m in MOODS" :key="m.id"><option :value="m.id" x-text="m.label"></option></template>
                </select>
              </div>
              <div>
                <span class="mu-label">Lisensi</span>
                <div class="seg" style="display:flex">
                  <button :class="{ on: license === 'cc-by' }" @click="license = 'cc-by'" type="button">CC BY (wajib kredit)</button>
                  <button :class="{ on: license === 'free' }" @click="license = 'free'" type="button">Bebas atribusi</button>
                </div>
              </div>
            </div>

            <div class="mu-queue">
              <template x-for="(q, i) in queue" :key="q.id">
                <div class="mu-item">
                  <div>
                    <div class="mu-item-name"><span x-text="q.file.name"></span><small x-text="fmtSize(q.file.size)"></small></div>
                    <input class="field" x-model="q.credit" :disabled="q.state === 'done' || q.state === 'uploading'"
                           :placeholder="license === 'cc-by' ? 'Tempel kredit, mis. Song by Artist is licensed under CC BY 4.0' : 'Kredit (opsional)'">
                    <div class="mu-bar" x-show="q.state === 'uploading'"><div :style="'width:' + q.progress + '%'"></div></div>
                    <div class="mu-status" :class="{ ok: q.state === 'done', err: q.state === 'error' }" x-show="q.message" x-text="q.message"></div>
                  </div>
                  <select class="select mu-item-mood" x-model="q.mood" :disabled="q.state === 'done' || q.state === 'uploading'">
                    <template x-for="m in MOODS" :key="m.id"><option :value="m.id" x-text="m.label" :selected="m.id === q.mood"></option></template>
                  </select>
                  <button class="mu-x" type="button" @click="queue.splice(i, 1)" :disabled="q.state === 'uploading'" aria-label="Hapus dari antrian">✕</button>
                </div>
              </template>
            </div>

            <div class="mu-actions">
              <span style="font-size:13px;color:var(--text-muted);margin-right:auto" x-text="queueSummary()"></span>
              <button class="btn btn-ghost ap-btn" type="button" @click="queue = queue.filter(q => q.state !== 'done')" x-show="queue.some(q => q.state === 'done')">Bersihkan yang selesai</button>
              <button class="btn ap-btn" type="button" @click="uploadAll()" :disabled="uploading || !pending().length"
                      x-text="uploading ? 'Mengupload…' : 'Upload ' + pending().length + ' lagu'"></button>
            </div>
          </div>
        </template>
      </div>
    </section>

    {{-- ── Library ────────────────────────────────────────────── --}}
    <section class="mu-sec">
      <h2>Library <span class="mu-count" x-text="tracks.length + ' lagu'"></span></h2>
      <div class="mu-moods">
        <div class="seg" style="flex-wrap:wrap">
          <button :class="{ on: filter === '' }" @click="filter = ''" type="button" x-text="'Semua · ' + tracks.length"></button>
          <template x-for="m in MOODS" :key="m.id">
            <button :class="{ on: filter === m.id }" @click="filter = m.id" type="button" x-text="m.label + ' · ' + (counts[m.id] || 0)"></button>
          </template>
        </div>
      </div>

      <div x-show="visible().length === 0" class="mu-empty" x-text="tracks.length ? 'Belum ada lagu untuk mood ini.' : 'Library masih kosong. Upload lagu di atas.'"></div>
      <div class="mu-card" style="padding:0" x-show="visible().length > 0">
        <template x-for="t in visible()" :key="t.path">
          <div class="mu-track">
            <div style="min-width:0">
              <div class="mu-track-name" x-text="t.name"></div>
              <div class="mu-track-meta">
                <span class="mu-lic" :class="t.license === 'free' ? 'free' : (t.credit ? 'cc' : 'none')"
                      x-text="t.license === 'free' ? 'Bebas atribusi' : (t.credit ? 'CC BY' : 'Kredit belum diisi')"></span>
                <span x-show="t.credit" x-text="t.credit" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:100%"></span>
              </div>
            </div>
            <div class="mu-track-tools">
              <select class="select" :value="t.mood || ''" @change="moveTrack(t, $event.target.value)" aria-label="Pindah mood">
                <template x-for="m in MOODS" :key="m.id"><option :value="m.id" x-text="m.label" :selected="m.id === t.mood"></option></template>
              </select>
              <button class="btn btn-ghost ap-btn" type="button" @click="t.editing = !t.editing; t.draft = t.credit || ''; t.draftLicense = t.license || 'cc-by'">Kredit</button>
              <button class="btn btn-ghost ap-btn" type="button" style="color:var(--danger)" @click="removeTrack(t)">Hapus</button>
            </div>
            <div class="mu-edit" x-show="t.editing">
              <div class="seg">
                <button :class="{ on: t.draftLicense === 'cc-by' }" @click="t.draftLicense = 'cc-by'" type="button">CC BY</button>
                <button :class="{ on: t.draftLicense === 'free' }" @click="t.draftLicense = 'free'" type="button">Bebas</button>
              </div>
              <input class="field" x-model="t.draft" placeholder="Teks kredit yang ditampilkan di caption">
              <button class="btn ap-btn" type="button" @click="saveCredit(t)">Simpan</button>
            </div>
            <audio controls preload="none" :src="'/api/music/file?path=' + encodeURIComponent(t.path)"></audio>
          </div>
        </template>
      </div>
      <div x-show="error" class="mu-status err" style="margin-top:10px" x-text="error"></div>
    </section>
  </div>
</div>

<script>
function musicLibrary() {
  const token = () => document.querySelector('meta[name=csrf-token]').content;
  const api = async (url, opts = {}) => {
    const res = await fetch(url, { headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': token() }, ...opts });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(firstError(data) || ('HTTP ' + res.status));
    return data;
  };
  const firstError = (data) => data.errors ? Object.values(data.errors)[0][0] : data.message;

  return {
    MOODS: [
      { id: 'energetic', label: 'Energik' }, { id: 'chill', label: 'Santai' }, { id: 'inspiring', label: 'Inspiratif' },
      { id: 'dramatic', label: 'Dramatis' }, { id: 'funny', label: 'Lucu' }, { id: 'sad', label: 'Sedih' },
    ],
    tracks: [], counts: {}, filter: '', error: null,
    queue: [], bulkMood: 'chill', license: 'cc-by', uploading: false, dragging: false, nextId: 1,

    async init() { await this.load(); },
    async load() {
      try {
        const data = await api('/api/music');
        this.tracks = data.tracks.map(t => ({ ...t, editing: false, draft: '', draftLicense: 'cc-by' }));
        this.counts = data.counts;
      } catch (e) { this.error = e.message; }
    },
    visible() { return this.filter ? this.tracks.filter(t => t.mood === this.filter) : this.tracks; },

    addFiles(list) {
      for (const file of list) {
        if (!/\.(mp3|m4a|aac|wav|ogg)$/i.test(file.name)) continue;
        this.queue.push({ id: this.nextId++, file, mood: this.bulkMood, credit: '', state: 'ready', progress: 0, message: '' });
      }
    },
    pending() { return this.queue.filter(q => q.state === 'ready' || q.state === 'error'); },
    queueSummary() {
      const done = this.queue.filter(q => q.state === 'done').length, err = this.queue.filter(q => q.state === 'error').length;
      return `${this.queue.length} file · ${done} selesai` + (err ? ` · ${err} gagal` : '');
    },
    fmtSize(b) { return b > 1048576 ? (b / 1048576).toFixed(1) + ' MB' : Math.round(b / 1024) + ' KB'; },

    // One request per file: shows progress and sidesteps PHP's max_file_uploads
    upload(q) {
      return new Promise(resolve => {
        const fd = new FormData();
        fd.append('file', q.file);
        fd.append('mood', q.mood);
        fd.append('license', this.license);
        if (q.credit.trim()) fd.append('credit', q.credit.trim());

        const xhr = new XMLHttpRequest();
        xhr.open('POST', '/api/music');
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.setRequestHeader('X-CSRF-TOKEN', token());
        xhr.upload.onprogress = e => { if (e.lengthComputable) q.progress = Math.round(e.loaded / e.total * 100); };
        xhr.onload = () => {
          let data = {};
          try { data = JSON.parse(xhr.responseText); } catch {}
          if (xhr.status === 401) { location.href = '/login?expired=1'; return; }
          if (xhr.status === 419) { location.reload(); return; }
          if (xhr.status >= 200 && xhr.status < 300) { q.state = 'done'; q.message = '✓ Tersimpan di ' + data.path; }
          else { q.state = 'error'; q.message = firstError(data) || ('Gagal (HTTP ' + xhr.status + ')'); }
          resolve();
        };
        xhr.onerror = () => { q.state = 'error'; q.message = 'Koneksi gagal.'; resolve(); };
        q.state = 'uploading'; q.message = ''; q.progress = 0;
        xhr.send(fd);
      });
    },
    async uploadAll() {
      const todo = this.pending();
      if (this.license === 'cc-by') {
        const missing = todo.filter(q => !q.credit.trim());
        if (missing.length) {
          missing.forEach(q => { q.state = 'error'; q.message = 'Isi kredit dulu — lagu CC BY wajib mencantumkan atribusi.'; });
          return;
        }
      }
      this.uploading = true;
      for (const q of todo) await this.upload(q);
      this.uploading = false;
      await this.load();
    },

    async saveCredit(t) {
      if (t.draftLicense === 'cc-by' && !t.draft.trim()) { this.error = 'Lagu CC BY wajib diberi kredit.'; return; }
      this.error = null;
      try {
        await api('/api/music', { method: 'PATCH', body: JSON.stringify({ path: t.path, license: t.draftLicense, credit: t.draft.trim() || null }) });
        await this.load();
      } catch (e) { this.error = e.message; }
    },
    async moveTrack(t, mood) {
      if (!mood || mood === t.mood) return;
      this.error = null;
      try {
        await api('/api/music', { method: 'PATCH', body: JSON.stringify({ path: t.path, mood }) });
        await this.load();
      } catch (e) { this.error = e.message; }
    },
    async removeTrack(t) {
      if (!confirm(`Hapus "${t.name}" dari library? Clip yang sudah dirender tidak berubah.`)) return;
      this.error = null;
      try {
        await api('/api/music', { method: 'DELETE', body: JSON.stringify({ path: t.path }) });
        await this.load();
      } catch (e) { this.error = e.message; }
    },
  };
}
</script>
</body>
</html>
