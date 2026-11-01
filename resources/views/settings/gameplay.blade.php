<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>Footage gameplay · Clipper Studio</title>
@include('partials.favicon')
<meta name="csrf-token" content="{{ csrf_token() }}" />
@include('partials.head')
@vite('resources/css/studio.css')
@include('partials.session-guard')
<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
<style>
  .gp-wrap { max-width: 720px; margin: 0 auto; padding: 40px 24px 96px; }
  .gp-wrap h1 { font-size: 28px; font-weight: 700; letter-spacing: -.02em; margin: 16px 0 8px; }
  .gp-wrap .lead { color: var(--text-muted); font-size: 14px; line-height: 1.6; margin: 0 0 24px; }
  .gp-meter { display: flex; align-items: baseline; justify-content: space-between; gap: 12px; flex-wrap: wrap; margin-bottom: 10px; }
  .gp-meter b { font-family: var(--font-mono); font-size: 13px; }
  .gp-limits { font-family: var(--font-mono); font-size: 12px; color: var(--text-dim); }
  .gp-drop { position: relative; display: block; border: 1.5px dashed var(--border-strong); border-radius: 12px; padding: 28px 16px; text-align: center; color: var(--text-muted); font-size: 14px; cursor: pointer; transition: border-color .15s, background .15s; }
  .gp-drop:hover, .gp-drop.over, .gp-drop:focus-within { border-color: var(--accent-ink); background: var(--accent-soft); color: var(--text); }
  .gp-drop b { color: var(--text); }
  .gp-drop input { position: absolute; inset: 0; width: 100%; height: 100%; opacity: 0; cursor: pointer; }
  .gp-drop.full { cursor: not-allowed; opacity: .7; }
  .gp-drop.full:hover { border-color: var(--border-strong); background: none; color: var(--text-muted); }
  .gp-drop.full input { cursor: not-allowed; }
  .gp-queue { list-style: none; margin: 14px 0 0; padding: 0; display: flex; flex-direction: column; gap: 8px; }
  .gp-q { padding: 10px 12px; background: var(--surface-2); border: 1px solid var(--border); border-radius: 10px; font-size: 13px; }
  .gp-q-name { font-weight: 600; overflow-wrap: anywhere; }
  .gp-q-msg { font-size: 12px; margin-top: 4px; color: var(--text-muted); }
  .gp-q-msg.ok { color: var(--accent-ink); }
  .gp-q-msg.err { color: var(--danger); }
  .gp-bar { height: 3px; border-radius: 999px; background: var(--surface-3); overflow: hidden; margin-top: 8px; }
  .gp-bar > div { height: 100%; background: var(--accent); transition: width .2s; }
  .gp-sec { margin-top: 36px; }
  .gp-sec h2 { font-size: 16px; margin: 0 0 4px; }
  .gp-list { list-style: none; margin: 12px 0 0; padding: 0; }
  .gp-row { display: grid; grid-template-columns: 72px minmax(0, 1fr) auto; gap: 14px; align-items: center; padding: 12px 0; border-top: 1px solid var(--border); }
  .gp-thumb { width: 72px; aspect-ratio: 9 / 16; border-radius: 8px; background: var(--surface-3); overflow: hidden; display: flex; align-items: center; justify-content: center; }
  .gp-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
  .gp-thumb span { font-family: var(--font-mono); font-size: 10px; color: var(--text-dim); text-align: center; padding: 4px; }
  .gp-name { font-weight: 600; font-size: 14px; overflow-wrap: anywhere; }
  .gp-meta { font-family: var(--font-mono); font-size: 12px; color: var(--text-dim); margin-top: 4px; }
  .gp-del { display: flex; gap: 8px; align-items: center; }
  .gp-err { color: var(--danger); font-size: 13px; margin-top: 10px; }
  @media (max-width: 480px) {
    .gp-wrap { padding: 24px 16px 72px; }
    .gp-row { grid-template-columns: 56px minmax(0, 1fr); }
    .gp-thumb { width: 56px; }
    .gp-del { grid-column: 1 / -1; justify-content: flex-end; }
  }
</style>
</head>
<body>
<div class="gp-wrap" x-data="gameplayFootage()" x-init="load()" x-cloak>
  <a href="{{ route('home') }}" class="btn-ghost btn-sm">← Kembali ke Studio</a>
  <h1>Footage gameplay</h1>
  <p class="lead">Video yang berputar di separuh bawah layout Split Gameplay, di bawah pembicara. Footage ini hanya bisa dipakai akunmu. Unggah klip yang memang boleh kamu publikasikan.</p>

  <div class="gp-meter">
    <b x-text="loaded ? clips.length + ' dari ' + max + ' footage' : 'Memuat…'"></b>
    <span class="gp-limits">MP4, MOV, M4V, WebM, MKV · maks {{ $maxMb }} MB · min 3 detik</span>
  </div>

  <label class="gp-drop" :class="{ over: dragging, full: loaded && remaining < 1 }"
         @dragover.prevent="dragging = remaining > 0" @dragleave="dragging = false" @drop.prevent="dragging = false; if (remaining > 0) add($event.dataTransfer.files)">
    <span x-show="!loaded || remaining > 0"><b>Pilih video</b> atau seret ke sini. Bisa beberapa file sekaligus.</span>
    <span x-show="loaded && remaining < 1"><b>Batas {{ $maxFiles }} footage tercapai.</b> Hapus salah satu untuk mengunggah yang baru.</span>
    <input type="file" multiple accept=".mp4,.mov,.m4v,.webm,.mkv,video/*" :disabled="!loaded || remaining < 1 || uploading"
           aria-label="Pilih video footage gameplay" @change="add($event.target.files); $event.target.value = ''" />
  </label>

  <ul class="gp-queue" x-show="queue.length" aria-live="polite">
    <template x-for="q in queue" :key="q.id">
      <li class="gp-q">
        <div class="gp-q-name" x-text="q.name"></div>
        <div class="gp-bar" x-show="q.state === 'uploading'"><div :style="'width:' + q.progress + '%'"></div></div>
        <div class="gp-q-msg" :class="{ ok: q.state === 'done', err: q.state === 'error' }" x-text="q.message"></div>
      </li>
    </template>
  </ul>

  <section class="gp-sec">
    <h2>Footage-mu</h2>
    <p class="fhint" x-show="loaded && !clips.length">Belum ada footage. Unggah klip pertamamu di atas, lalu pilih layout Split Gameplay di Studio.</p>
    <p class="gp-err" x-show="error" x-text="error" role="alert"></p>
    <ul class="gp-list">
      <template x-for="c in clips" :key="c.id">
        <li class="gp-row">
          <div class="gp-thumb"><img x-show="c.thumb" :src="c.thumb" alt="" loading="lazy" /><span x-show="!c.thumb">tanpa pratinjau</span></div>
          <div>
            <div class="gp-name" x-text="c.name"></div>
            <div class="gp-meta" x-text="fmtDuration(c.duration) + ' · ' + c.width + '×' + c.height + ' · ' + fmtSize(c.size)"></div>
          </div>
          <div class="gp-del">
            <template x-if="confirming !== c.id">
              <button type="button" class="btn-secondary btn-sm" @click="confirming = c.id" :aria-label="'Hapus ' + c.name">Hapus</button>
            </template>
            <template x-if="confirming === c.id">
              <span class="gp-del">
                <button type="button" class="btn-secondary btn-sm" @click="remove(c)" :aria-label="'Ya, hapus ' + c.name">Ya, hapus</button>
                <button type="button" class="btn-ghost btn-sm" @click="confirming = null">Batal</button>
              </span>
            </template>
          </div>
        </li>
      </template>
    </ul>
    <p class="fhint" style="margin-top:14px" x-show="clips.length">Render yang sudah berjalan tidak terganggu. Render yang masih antre memilih dari footage yang tersisa, atau memakai reframe biasa kalau semuanya sudah dihapus.</p>
  </section>
</div>

<script>
function gameplayFootage() {
  const csrf = () => document.querySelector('meta[name=csrf-token]').content;
  const firstError = (data) => data.errors ? Object.values(data.errors)[0][0] : data.message;
  const MAX_BYTES = {{ $maxMb }} * 1048576;
  return {
    clips: [], max: {{ $maxFiles }}, remaining: 0, loaded: false, error: '',
    queue: [], nextId: 1, uploading: false, dragging: false, confirming: null,

    fmtSize(b) { return b >= 1048576 ? (b / 1048576).toFixed(1).replace('.', ',') + ' MB' : Math.round(b / 1024) + ' KB'; },
    fmtDuration(s) { const m = Math.floor(s / 60), r = Math.round(s % 60); return m + ':' + String(r).padStart(2, '0'); },

    async load() {
      try {
        const res = await fetch('/api/gameplay', { headers: { 'Accept': 'application/json' } });
        if (res.status === 401) { location.href = '/login?expired=1'; return; }
        if (!res.ok) throw new Error('Gagal memuat daftar footage. Muat ulang halaman.');
        const data = await res.json();
        this.clips = data.clips; this.max = data.max; this.remaining = data.remaining;
      } catch (e) { this.error = e.message; }
      this.loaded = true;
    },

    async add(files) {
      this.error = '';
      let room = this.remaining;
      const items = [];
      for (const file of Array.from(files)) {
        const q = { id: this.nextId++, name: file.name, file, state: 'waiting', progress: 0, message: 'Menunggu giliran' };
        if (file.size > MAX_BYTES) { q.state = 'error'; q.message = 'Ukuran file maksimal {{ $maxMb }} MB.'; }
        else if (room < 1) { q.state = 'error'; q.message = 'Batas {{ $maxFiles }} footage tercapai.'; }
        else { room--; items.push(q); }
        this.queue.push(q);
      }
      this.uploading = true;
      for (const q of items) await this.upload(q);
      this.uploading = false;
      await this.load();
    },

    // One request per file: shows progress and keeps each request under PHP's upload limits
    upload(q) {
      return new Promise(resolve => {
        const fd = new FormData();
        fd.append('file', q.file);
        const xhr = new XMLHttpRequest();
        xhr.open('POST', '/api/gameplay');
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.setRequestHeader('X-CSRF-TOKEN', csrf());
        xhr.upload.onprogress = e => {
          if (!e.lengthComputable) return;
          q.progress = Math.round(e.loaded / e.total * 100);
          if (q.progress >= 100) q.message = 'Memeriksa video…';
        };
        xhr.onload = () => {
          let data = {};
          try { data = JSON.parse(xhr.responseText); } catch {}
          if (xhr.status === 401) { location.href = '/login?expired=1'; return; }
          if (xhr.status === 419) { location.reload(); return; }
          if (xhr.status >= 200 && xhr.status < 300) { q.state = 'done'; q.message = 'Tersimpan'; }
          else if (xhr.status === 413) { q.state = 'error'; q.message = 'Server menolak ukuran file ini.'; }
          else { q.state = 'error'; q.message = firstError(data) || ('Gagal (HTTP ' + xhr.status + ')'); }
          q.file = null;
          resolve();
        };
        xhr.onerror = () => { q.state = 'error'; q.message = 'Koneksi gagal. Coba lagi.'; q.file = null; resolve(); };
        q.state = 'uploading'; q.message = 'Mengunggah…'; q.progress = 0;
        xhr.send(fd);
      });
    },

    async remove(c) {
      this.error = '';
      try {
        const res = await fetch('/api/gameplay/' + c.id, { method: 'DELETE', headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf() } });
        if (res.status === 401) { location.href = '/login?expired=1'; return; }
        if (!res.ok && res.status !== 404) throw new Error('Gagal menghapus. Coba lagi.');
        this.confirming = null;
        await this.load();
      } catch (e) { this.error = e.message; }
    },
  };
}
</script>
</body>
</html>
