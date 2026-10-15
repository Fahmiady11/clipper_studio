<script>
function studio() {
  const SESSION_KEY = 'clipper_studio_v1';
  const PERSIST_KEYS = [
    'step','maxReached','done','locked',
    'url','meta',
    'layout','jumpCut','autoZoom','progressBar','topTitle','broll',
    'clipCount','durationMode','minDuration','maxDuration',
    'subtitleEnabled','subtitleFont','subtitleSize','subtitleTextColor','subtitleHighlight','subtitlePos','subtitleY','subtitleBg','subtitleStyle','subtitleEmoji',
    'hookEnabled','hookText','hookAi','hookDuration','hookPos','hookTextColor','hookBg',
    'musicEnabled','musicMood','musicVolume',
    'generateStarted','generateProgress','generateLogIdx',
    'projectId','clips','selectedClip','renderingClipId',
    'downloadUrl','resultHookText',
  ];

  const SAMPLE_SEGS = [
    { start: 0,   end: 2.2,  text: 'Setiap momen adalah kesempatan' },
    { start: 2.2, end: 4.4,  text: 'untuk mengubah segalanya' },
    { start: 4.4, end: 6.8,  text: 'mulai dari sekarang' },
    { start: 6.8, end: 9.0,  text: 'itu saja sudah cukup' },
  ];

  function buildWords(segs) {
    const out = [];
    let idx = 0;
    segs.forEach(seg => {
      const ws = seg.text.split(' ');
      const per = (seg.end - seg.start) / ws.length;
      ws.forEach((w, i) => out.push({ idx: idx++, w, start: seg.start + i * per, end: seg.start + (i + 1) * per, segStart: seg.start, segEnd: seg.end }));
    });
    return out;
  }

  const ALL_WORDS = buildWords(SAMPLE_SEGS);

  return {
    // ── state ──────────────────────────────────────────────────────────────
    step: 1, maxReached: 1, done: false, locked: false,
    sessionId: null, historyOpen: false, history: [], clearingHistory: false,

    url: '', meta: null, loadingMeta: false, metaError: null,
    layout: 'reframe', jumpCut: true, autoZoom: true, progressBar: true, topTitle: false, broll: true,
    clipCount: 3, durationMode: 'auto', minDuration: 20, maxDuration: 45,
    subtitleEnabled: true, subtitleFont: 'Montserrat', subtitleSize: 56,
    subtitleTextColor: '#ffffff', subtitleHighlight: '#facc15',
    subtitlePos: 'bottom', subtitleY: null, subtitleBg: 'semi', subtitleStyle: 'karaoke', subtitleEmoji: true, subDragging: false,
    hookEnabled: true, hookText: 'Ingin lebih baik? Coba ini.', hookAi: false,
    hookDuration: 3, hookPos: 'center', hookTextColor: '#ffffff', hookBg: 'semi',
    musicEnabled: false, musicMood: '', musicVolume: 25, musicLibrary: null, musicMissing: false,
    generateStarted: false, generateProgress: 0, generateLogIdx: 0, generateTimer: null,
    generateError: null, generateLogMsg: null, generateCancelled: false, cancelling: false, retrying: false,
    projectId: null,
    clips: [], selectedClip: null,
    renderingClipId: null, renderError: null, downloadUrl: null, renderTimer: null,
    fontsTick: 0,
    resultHookText: 'Ingin lebih baik? Coba ini.',
    editHookOpen: false, draftHook: '', copied: '',
    batchTimer: null, batchStarting: false,
    trim: { open: false, clip: null, words: [], start: 0, end: 0, mode: 'start', loading: false, saving: false, error: null },
    clipCaption: '', clipHashtags: [], loadingCaption: false,
    tiktokConfigured: false, tiktokAccounts: [], tiktokAccountId: null,
    tiktokStatus: null, tiktokError: null, tiktokTimer: null,
    tiktokMode: 'inbox', tiktokCreator: null, tiktokCreatorError: null, tiktokCreatorLoading: false,
    tiktokTitle: '', tiktokPrivacy: '', tiktokAllowComment: false, tiktokAllowDuet: false, tiktokAllowStitch: false,
    PRIVACY_LABELS: {
      PUBLIC_TO_EVERYONE: 'Publik', MUTUAL_FOLLOW_FRIENDS: 'Teman', FOLLOWER_OF_CREATOR: 'Follower', SELF_ONLY: 'Hanya saya',
    },
    previewT: 0, previewTick: null,

    // ── constants ──────────────────────────────────────────────────────────
    STEPS: [
      { n:1, key:'link',     label:'Link' },
      { n:2, key:'layout',   label:'Layout' },
      { n:3, key:'clips',    label:'Clip' },
      { n:4, key:'subtitle', label:'Subtitle' },
      { n:5, key:'hook',     label:'Hook' },
      { n:6, key:'generate', label:'Generate' },
      { n:7, key:'pick',     label:'Pilih' },
    ],
    LAYOUTS: [
      { id:'reframe',  title:'Reframe Wajah', sub:'Face tracking',      color:'teal',   accent:'#2dd4bf', badge:{ text:'DISARANKAN', cls:'badge-teal' },   desc:'Video di-crop 9:16 dan kamera mengikuti wajah. Di podcast, pindah ke orang yang sedang bicara.', good:'Podcast, wawancara, talking head, vlog' },
      { id:'gaussian', title:'Gaussian',      sub:'Blurred background', color:'purple', accent:'#a78bfa', badge:null,                                        desc:'Video 16:9 di tengah, area kosong diisi duplikat blur + zoom.',      good:'Gameplay, screen recording, tutorial, olahraga' },
    ],
    FONTS: ['Arial','Roboto','Montserrat','Oswald','Bebas Neue'],
    COLOR_PRESETS: ['#ffffff','#facc15','#2dd4bf','#a78bfa','#60a5fa','#fb7185','#f97316','#000000'],
    MUSIC_MOODS: [
      { id:'energetic', label:'Energik' }, { id:'chill', label:'Santai' }, { id:'inspiring', label:'Inspiratif' },
      { id:'dramatic', label:'Dramatis' }, { id:'funny', label:'Lucu' }, { id:'sad', label:'Sedih' },
    ],
    HL_PRESETS:    ['#facc15','#2dd4bf','#a78bfa','#60a5fa','#fb7185','#22c55e','#ffffff','#f97316'],
    STAGES: [
      { msg:'AnalyzeVideoJob di-dispatch ke queue',           dur:600  },
      { msg:'Mengunduh video via yt-dlp → storage/temp/',     dur:1400 },
      { msg:'Mengambil transcript YouTube (bahasa: id)',       dur:1000 },
      { msg:'Mengirim transcript ke Gemini · pilih N momen',  dur:1500 },
      { msg:'Menyusun segmen subtitle per kata',              dur:1100 },
      { msg:'Menyimpan hasil ke generated_clips',             dur:700  },
    ],
    SAMPLE_CLIPS: [
      { ranking:1, start_seconds:723,  end_seconds:768,  topic:'Aturan 2 menit yang mengubah segalanya',  reason:'Insight kuat + langsung bisa dipraktikkan.',  viral_potential:'tinggi', hook_text:'Kebiasaan gagal? Coba aturan 2 menit ini.' },
      { ranking:2, start_seconds:1490, end_seconds:1528, topic:'Kenapa motivasi selalu mengkhianatimu',    reason:'Momen emosional dengan pernyataan kontroversial.', viral_potential:'tinggi', hook_text:'Berhenti menunggu motivasi datang.' },
      { ranking:3, start_seconds:305,  end_seconds:340,  topic:'Lingkungan mengalahkan niat',             reason:'Tips praktis yang relatable.',                viral_potential:'sedang', hook_text:'Ubah ruanganmu, bukan dirimu.' },
      { ranking:4, start_seconds:1980, end_seconds:2012, topic:'Identitas di atas hasil',                 reason:'Pesan reflektif, engagement bagus.',          viral_potential:'sedang', hook_text:'Jangan kejar hasil, kejar identitas.' },
      { ranking:5, start_seconds:88,   end_seconds:116,  topic:'Mitos 21 hari membentuk kebiasaan',       reason:'Membongkar mitos populer.',                   viral_potential:'rendah', hook_text:'Lupakan angka 21 hari.' },
    ],
    SAMPLE_SEGS,
    SAMPLE_CAPTION: 'Aturan 2 menit yang bikin kebiasaanmu akhirnya nempel. Simpan biar nggak lupa 🔖\n\n#KebiasaanBaik #ProduktivitasHarian #SelfImprovement #MindsetJuara #TipsDisiplin',
    SAMPLE_HASHTAGS: ['#KebiasaanBaik','#ProduktivitasHarian','#SelfImprovement','#MindsetJuara','#TipsDisiplin'],
    sampleMeta: { channel:'Pikiran Jernih' },

    // ── getters ────────────────────────────────────────────────────────────
    get isValidUrl() {
      return /(?:youtube\.com\/(?:watch\?v=|shorts\/|embed\/)|youtu\.be\/)[A-Za-z0-9_-]{6,}/.test(this.url);
    },
    get canNext() {
      if (this.step === 1) return !!this.meta;
      if (this.step === 2) return !!this.layout;
      if (this.step === 7) return !!this.selectedClip;
      return true;
    },
    get currentLayout() {
      return this.LAYOUTS.find(l => l.id === this.layout) || this.LAYOUTS[0];
    },
    get selectedClipObj() {
      return this.clips.find(c => c.ranking === this.selectedClip) || null;
    },
    get pvHookText()  { return this.hookAi ? this.SAMPLE_CLIPS[0].hook_text : this.hookText; },
    get pvHookDur()   { return this.hookEnabled && this.pvHookText ? this.hookDuration : 0; },
    get pvSubTotal()  { return SAMPLE_SEGS.length ? SAMPLE_SEGS[SAMPLE_SEGS.length-1].end : 10.2; },
    get pvLoopTotal() { return this.pvHookDur + this.pvSubTotal; },
    get pvLt()        { return this.pvLoopTotal > 0 ? this.previewT % this.pvLoopTotal : 0; },
    get pvInHook()    { return this.pvLt < this.pvHookDur; },
    get pvSubT()      { return Math.max(0, this.pvLt - this.pvHookDur); },
    get pvActiveWord() {
      if (this.pvInHook) return null;
      const t = this.pvSubT;
      return ALL_WORDS.find(w => t >= w.start && t < w.end) || ALL_WORDS[0];
    },
    get pvSegWords() {
      const aw = this.pvActiveWord;
      if (!aw) return [];
      return ALL_WORDS.filter(w => w.segStart === aw.segStart).map(w => ({ ...w, active: w.start === aw.start }));
    },
    get pvSubWordsAlways() {
      const t = this.pvSubT;
      const aw = ALL_WORDS.find(w => t >= w.start && t < w.end) || ALL_WORDS[0];
      if (!aw) return [];
      return ALL_WORDS.filter(w => w.segStart === aw.segStart).map(w => ({ ...w, active: w.start === aw.start }));
    },
    get pvProgress() {
      return this.pvLoopTotal > 0 ? (this.pvLt / this.pvLoopTotal) * 100 : 0;
    },

    // ── preview style helpers (shared with autopilot: partials/clip-preview-js) ──
    pvSubStyle()     { return ClipPreview.subStyle(this.subtitlePos, this.subtitleY); },
    startSubDrag(e) {
      this.subDragging = true;
      ClipPreview.dragSubtitle(e, y => { this.subtitleY = y; }, () => { this.subDragging = false; });
    },
    pvSubBoxStyle()  { return ClipPreview.subBoxStyle({ font: this.subtitleFont, size: this.subtitleSize, bg: this.subtitleBg, style: this.subtitleStyle }); },
    get pvEmojiBelow() { return ClipPreview.emojiBelow(this.subtitlePos, this.subtitleY); },
    get pvSubWords() { return ClipPreview.subWords(this.pvSubWordsAlways, this.subtitleStyle); },
    pvSubWordStyle(w) { return ClipPreview.subWordStyle(w, { style: this.subtitleStyle, text: this.subtitleTextColor, hl: this.subtitleHighlight }); },
    get subPreset() {
      return ClipPreview.presetOf({
        font_family: this.subtitleFont, font_size: this.subtitleSize, text_color: this.subtitleTextColor,
        highlight_color: this.subtitleHighlight, background_style: this.subtitleBg, style: this.subtitleStyle,
      });
    },
    applySubPreset(p) {
      const s = p.set;
      Object.assign(this, {
        subtitleFont: s.font_family, subtitleSize: s.font_size, subtitleTextColor: s.text_color,
        subtitleHighlight: s.highlight_color, subtitleBg: s.background_style, subtitleStyle: s.style,
        subtitlePos: s.position, subtitleY: s.position_y,
      });
    },
    pvHook(text)     { return ClipPreview.hookLayout(text, this.subtitleFont, this.fontsTick); },
    pvHookBlockStyle(text) {
      return ClipPreview.hookStyle(text, {
        family: this.subtitleFont, color: this.hookTextColor, accent: this.subtitleHighlight,
        bg: this.hookBg, pos: this.hookPos, tick: this.fontsTick,
      });
    },

    // ── persist: session ───────────────────────────────────────────────────
    saveSession() {
      const state = { sessionId: this.sessionId };
      PERSIST_KEYS.forEach(k => state[k] = this[k]);
      try { localStorage.setItem(SESSION_KEY, JSON.stringify(state)); } catch {}
    },
    loadSession() {
      try {
        const raw = localStorage.getItem(SESSION_KEY);
        if (!raw) { this.sessionId = this._uuid(); return; }
        const s = JSON.parse(raw);
        PERSIST_KEYS.forEach(k => { if (k in s) this[k] = s[k]; });
        this.sessionId = s.sessionId || this._uuid();
      } catch { this.sessionId = this._uuid(); }
    },
    clearSession() {
      try { localStorage.removeItem(SESSION_KEY); } catch {}
      this.sessionId = this._uuid();
    },
    watchAll() {
      PERSIST_KEYS.forEach(k => this.$watch(k, () => this.saveSession()));
    },

    // ── history: DB-backed ─────────────────────────────────────────────────
    async loadHistory() {
      try {
        const res = await fetch('/api/projects', {
          headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content }
        });
        if (!res.ok) return;
        const data = await res.json();
        this.history = data
          .filter(p => p.status !== 'failed')
          .map(p => {
            const st = p.status === 'done'
              ? (p.rendered_clip_id ? 'rendered' : 'generated')
              : 'processing';
            return {
              id:             p.project_id,
              projectId:      p.project_id,
              status:         st,
              savedAt:        p.created_at,
              title:          p.title || 'Menganalisis…',
              thumbnailUrl:   p.thumbnail_url || '',
              layout:         p.layout_type,
              renderedClipId: p.rendered_clip_id || null,
              downloadUrl:    p.rendered_clip_id ? `/api/clips/${p.rendered_clip_id}/download` : null,
            };
          });
      } catch {}
    },
    async deleteHistoryItem(item) {
      const running = item.status === 'processing' ? ' Proses yang masih berjalan akan dihentikan.' : '';
      if (!confirm(`Hapus "${item.title}" beserta clip hasil rendernya?${running}`)) return;
      this.history = this.history.filter(h => h.id !== item.id);
      try {
        const res = await fetch(`/api/projects/${item.id}`, {
          method: 'DELETE',
          headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content }
        });
        if (!res.ok && res.status !== 404) throw new Error('HTTP ' + res.status);
        if (item.projectId === this.projectId) this.restart(); // the open project is gone
      } catch (e) {
        alert('Gagal menghapus riwayat. Coba lagi.');
        this.loadHistory();
      }
    },
    async clearAllHistory() {
      if (!this.history.length) return;
      if (!confirm(`Hapus semua ${this.history.length} riwayat beserta clip hasil rendernya? Tindakan ini tidak bisa dibatalkan.`)) return;
      const ids = this.history.map(h => h.id);
      this.clearingHistory = true;
      try {
        const res = await fetch('/api/projects', {
          method: 'DELETE',
          headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content }
        });
        if (!res.ok) throw new Error('HTTP ' + res.status);
        this.history = [];
        if (ids.includes(this.projectId)) this.restart();
      } catch (e) {
        alert('Gagal menghapus riwayat. Coba lagi.');
        this.loadHistory();
      } finally {
        this.clearingHistory = false;
      }
    },
    async restoreFromHistory(item) {
      // Leave the current project: its jobs keep running on the server, we just stop watching
      clearTimeout(this.generateTimer);
      clearTimeout(this.renderTimer);
      Object.assign(this, {
        step: 6, maxReached: 6, done: false, locked: true,
        generateStarted: true, generateProgress: 0, generateLogIdx: 0,
        generateError: null, generateLogMsg: 'Memuat status…', generateCancelled: false,
        projectId: item.projectId,
        meta: { title: item.title, thumbnailUrl: item.thumbnailUrl, durationSeconds: 0, channel: '', language: '' },
        clips: [], selectedClip: null,
        downloadUrl: null, renderingClipId: null, renderError: null, musicMissing: false,
        clipCaption: '', clipHashtags: [],
      });
      this.historyOpen = false;

      let data;
      try {
        const res = await fetch(`/api/projects/${item.projectId}/status`, { headers: { 'Accept': 'application/json' } });
        if (!res.ok) throw new Error('HTTP ' + res.status);
        data = await res.json();
      } catch (e) {
        this.generateError = 'Gagal memuat project. Coba lagi.';
        return;
      }
      if (this.projectId !== item.projectId) return; // another item was opened meanwhile

      // Still analysing: show the stage the server is actually at and keep watching
      if (data.status !== 'done') {
        this.applyGenerateStatus(item.projectId, data);
        if (data.status !== 'failed' && !data.cancelled) this.resumeGeneratePolling(item.projectId);
        return;
      }

      this.clips = this.mapClips(data.clips);
      this.step = 7;
      this.maxReached = 7;

      // Clips still rendering (started before switching away): pick them up again
      const renderingAll = this.clips.filter(c => c.status === 'processing');
      const rendering = renderingAll.length === 1 ? renderingAll[0] : null;
      if (renderingAll.length > 1) {
        this.watchBatch(0); // a "Render semua" batch
      } else if (rendering) {
        this.selectedClip = rendering.ranking;
        this.renderingClipId = rendering.id;
        this.watchRender(rendering.id);
      } else if (item.renderedClipId) {
        // Auto-select the rendered clip so caption can be fetched on re-entry
        const rendered = this.clips.find(c => c.id === item.renderedClipId);
        if (rendered) this.selectedClip = rendered.ranking;
        if (item.downloadUrl) {
          this.downloadUrl = item.downloadUrl;
          this.done = true;
        }
      }

      this.saveSession();
    },

    // ── resume on refresh ──────────────────────────────────────────────────
    resumeIfNeeded() {
      if (this.generateStarted && this.projectId && this.clips.length === 0 && this.step === 6) {
        this.resumeGeneratePolling(this.projectId);
      }
      // Reloaded mid-render: the job kept going, so keep watching it
      if (this.renderingClipId) this.watchRender(this.renderingClipId);
    },

    // ── navigation ─────────────────────────────────────────────────────────
    goStep(n) {
      if (this.locked && n < this.step) return;
      this.done = false;
      this.step = n;
      this.maxReached = Math.max(this.maxReached, n);
    },
    next() { this.goStep(Math.min(7, this.step + 1)); },
    back() {
      if (this.done) { this.done = false; return; }
      if (this.locked) return;
      this.step = Math.max(1, this.step - 1);
    },

    // ── step 1: metadata ───────────────────────────────────────────────────
    async processLink() {
      if (!this.isValidUrl) return;
      this.loadingMeta = true;
      this.metaError = null;
      try {
        const res = await fetch('/api/meta?' + new URLSearchParams({ url: this.url }), {
          headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, 'Accept': 'application/json' }
        });
        const data = await res.json();
        if (!res.ok) { this.metaError = data.error || data.message || `HTTP ${res.status} — Gagal mengambil metadata.`; return; }
        this.meta = {
          title: data.title,
          durationSeconds: data.duration_seconds,
          channel: data.channel,
          language: data.language,
          thumbnailUrl: data.thumbnail_url,
        };
      } catch (e) {
        this.metaError = 'Koneksi gagal. Coba lagi.';
      } finally {
        this.loadingMeta = false;
      }
    },

    // ── step 6: generate + polling ─────────────────────────────────────────
    async startGenerate() {
      this.generateCancelled = false;
      this.generateStarted = true;
      this.generateProgress = 5;
      this.generateLogIdx = 1;
      this.generateError = null;

      let projectId;
      try {
        const res = await fetch('/api/projects', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
          },
          body: JSON.stringify({
            youtube_url: this.url,
            layout_type: this.layout,
            edit: { jump_cut: this.jumpCut, zoom: this.autoZoom, progress: this.progressBar, title: this.topTitle, broll: this.broll },
            clip_count: this.clipCount,
            duration_mode: this.durationMode,
            min_duration: this.durationMode === 'manual' ? this.minDuration : null,
            max_duration: this.durationMode === 'manual' ? this.maxDuration : null,
            subtitle: {
              enabled: this.subtitleEnabled,
              font_family: this.subtitleFont,
              font_size: this.subtitleSize,
              text_color: this.subtitleTextColor,
              highlight_color: this.subtitleHighlight,
              position: this.subtitlePos,
              position_y: this.subtitleY,
              background_style: this.subtitleBg,
              style: this.subtitleStyle,
              emoji: this.subtitleEmoji,
            },
            hook: {
              enabled: this.hookEnabled,
              hook_text: this.hookAi ? null : this.hookText,
              is_ai_generated: this.hookAi,
              duration_seconds: this.hookDuration,
              position: this.hookPos,
              text_color: this.hookTextColor,
              background_style: this.hookBg,
            },
            music: {
              enabled: this.musicEnabled,
              mood: this.musicMood || null,
              volume: this.musicVolume,
            },
          }),
        });
        const data = await res.json();
        if (!res.ok) { this.generateError = data.message || 'Gagal membuat project.'; this.generateStarted = false; return; }
        projectId = data.project_id;
        this.projectId = projectId;
        this.locked = true;
      } catch (e) {
        this.generateError = 'Koneksi gagal saat membuat project.';
        this.generateStarted = false;
        return;
      }

      this.resumeGeneratePolling(projectId, 1500);
    },

    mapClips(list) {
      return (list || []).map(c => ({
        ranking: c.ranking, start_seconds: c.start_seconds, end_seconds: c.end_seconds,
        topic: c.topic, reason: c.reason, viral_potential: c.viral_potential,
        hook_text: c.hook_text, id: c.id, status: c.status, error_msg: c.error_msg || null,
      }));
    },

    // Apply a /status response for the project being analysed. Returns false once
    // there is nothing left to poll (done, failed, cancelled, or another project open).
    applyGenerateStatus(projectId, data) {
      if (this.projectId !== projectId) return false; // user switched project: ignore late replies
      const TOTAL_STAGES = this.STAGES.length;

      if (data.cancelled) { this.resetAfterCancel(); return false; }
      if (data.status === 'failed') {
        this.generateError = data.error_msg || 'Analisis gagal.';
        this.generateStarted = false;
        this.locked = false; // let them adjust settings before trying again
        return false;
      }

      // Stages only move forward; never jump back to an earlier one on screen
      if (data.progress_stage > 0 && data.progress_stage >= this.generateLogIdx) {
        this.generateLogIdx   = data.progress_stage;
        this.generateLogMsg   = data.progress_message || null;
        this.generateProgress = Math.round((data.progress_stage / TOTAL_STAGES) * 100);
      } else if (!data.progress_stage) {
        this.generateLogMsg = 'Menunggu antrian…';
        this.generateLogIdx = Math.max(this.generateLogIdx, 1);
      }

      if (data.status === 'done') {
        this.generateProgress = 100;
        this.generateLogIdx = TOTAL_STAGES;
        this.clips = this.mapClips(data.clips);
        setTimeout(() => { if (this.projectId === projectId) this.goStep(7); }, 600);
        return false;
      }
      return true;
    },

    resumeGeneratePolling(projectId, delay = 0) {
      clearTimeout(this.generateTimer);
      const poll = async () => {
        if (this.projectId !== projectId) return;
        try {
          const res = await fetch(`/api/projects/${projectId}/status`, {
            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content }
          });
          if (this.projectId !== projectId) return;

          if (res.status === 404) {
            // Project hilang dari DB (dihapus dari riwayat) — reset session
            this.restart();
            return;
          }
          if (!res.ok) {
            this.generateTimer = setTimeout(poll, 5000); // transient server hiccup: keep trying
            return;
          }

          if (this.applyGenerateStatus(projectId, await res.json())) {
            this.generateTimer = setTimeout(poll, 3000);
          }
        } catch (e) {
          if (this.projectId === projectId) this.generateTimer = setTimeout(poll, 3000);
        }
      };
      this.generateTimer = setTimeout(poll, delay);
    },

    async retryGenerate() {
      const projectId = this.projectId;
      if (!projectId || this.retrying) return;
      this.retrying = true;
      try {
        const res = await fetch(`/api/projects/${projectId}/retry`, {
          method: 'POST',
          headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content }
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok) { this.generateError = data.message || 'Gagal mengulang analisis.'; return; }
        Object.assign(this, {
          generateError: null, generateStarted: true, locked: true,
          generateProgress: 0, generateLogIdx: 0, generateLogMsg: 'Diulang — menunggu antrian…',
        });
        this.resumeGeneratePolling(projectId, 1500);
      } catch (e) {
        this.generateError = 'Koneksi gagal. Coba lagi.';
      } finally {
        this.retrying = false;
      }
    },

    async cancelGenerate() {
      if (!this.projectId || this.cancelling) return;
      if (!confirm('Batalkan analisis video ini? Proses yang sedang berjalan akan dihentikan.')) return;
      this.cancelling = true;
      clearTimeout(this.generateTimer);
      try {
        const res = await fetch(`/api/projects/${this.projectId}/cancel`, {
          method: 'POST',
          headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content }
        });
        if (res.status === 409) { this.resumeGeneratePolling(this.projectId); return; } // finished meanwhile
        if (!res.ok) throw new Error('HTTP ' + res.status);
        this.resetAfterCancel();
      } catch (e) {
        this.generateError = 'Gagal membatalkan. Coba lagi.';
        this.resumeGeneratePolling(this.projectId);
      } finally {
        this.cancelling = false;
      }
    },
    resetAfterCancel() {
      clearTimeout(this.generateTimer);
      Object.assign(this, {
        generateStarted: false, generateProgress: 0, generateLogIdx: 0, generateLogMsg: null,
        generateError: null, generateCancelled: true, projectId: null, locked: false,
      });
    },

    // ── step 7: render + polling ───────────────────────────────────────────
    // hookText given = re-render with an edited hook (from the result screen)
    async renderClip(hookText = undefined) {

      if (!this.selectedClipObj) return;
      const clipId = this.selectedClipObj.id;
      this.renderingClipId = clipId;
      this.renderError = null;

      try {
        const res = await fetch(`/api/clips/${clipId}/render`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
          body: JSON.stringify(hookText === undefined ? {} : { hook_text: hookText }),
        });
        const startData = await res.json();
        if (!res.ok) {
          this.renderError = startData.message || `Gagal memulai render (HTTP ${res.status}).`;
          this.renderingClipId = null;
          return;
        }
        // // Clip already done — set downloadUrl langsung tanpa polling
        // if (startData.status === 'done') {
        //   const stRes = await fetch(`/api/clips/${clipId}/render-status`, {
        //     headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content }
        //   });
        //   const stData = await stRes.json();
        //   this.downloadUrl = stData.download_url;
        //   this.done = true;
        //   this.renderingClipId = null;
        //   return;
        // }
      } catch (e) {
        this.renderError = 'Koneksi gagal.'; return;
      }

      this.resultHookText = hookText !== undefined && hookText !== ''
        ? hookText
        : (this.hookAi ? (this.selectedClipObj.hook_text || this.hookText) : this.hookText);

      this.watchRender(clipId, 3000);
    },

    // Poll a clip's render until it finishes; stops as soon as the user leaves it
    watchRender(clipId, delay = 0) {
      clearTimeout(this.renderTimer);
      const pollRender = async () => {
        if (this.renderingClipId !== clipId) return; // cancelled or switched project
        try {
          const res = await fetch(`/api/clips/${clipId}/render-status`, {
            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content }
          });
          const data = await res.json();
          if (this.renderingClipId !== clipId) return;
          if (data.status === 'pending') { this.renderingClipId = null; return; } // cancelled elsewhere
          if (data.status === 'done') {
            this.downloadUrl = data.download_url;
            this.musicMissing = !!data.music_missing;
            const clip = this.clips.find(c => c.id === clipId);
            if (clip) clip.status = 'done';
            this.done = true;
            this.renderingClipId = null;
            return;
          }
          if (data.status === 'failed') {
            this.renderError = data.error_msg || 'Render gagal.';
            this.renderingClipId = null;
            return;
          }
          this.renderTimer = setTimeout(pollRender, 3000);
        } catch (e) {
          this.renderTimer = setTimeout(pollRender, 3000);
        }
      };
      this.renderTimer = setTimeout(pollRender, delay);
    },
    async cancelRender() {
      const clipId = this.renderingClipId;
      if (!clipId || this.cancelling) return;
      if (!confirm('Batalkan render clip ini?')) return;
      this.cancelling = true;
      try {
        const res = await fetch(`/api/clips/${clipId}/cancel`, {
          method: 'POST',
          headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content }
        });
        if (res.ok || res.status === 409) {
          clearTimeout(this.renderTimer);
          this.renderingClipId = null;
        }
      } catch (e) {
        this.renderError = 'Gagal membatalkan render. Coba lagi.';
      } finally {
        this.cancelling = false;
      }
    },
    openEditHook() {
      this.draftHook = this.resultHookText || '';
      this.editHookOpen = true;
    },
    saveHookAndRerender() {
      const text = this.draftHook.trim();
      this.editHookOpen = false;
      this.done = false;
      this.step = 7;
      this.downloadUrl = null;
      this.renderClip(text);
    },

    // ── render every clip at once ────────────────────────────────────────────
    get unrenderedClips() { return this.clips.filter(c => c.status !== 'done' && c.status !== 'processing'); },
    get doneClips() { return this.clips.filter(c => c.status === 'done').length; },
    get batchRendering() { return !this.renderingClipId && this.clips.some(c => c.status === 'processing'); },
    async renderAll() {
      if (this.batchStarting || this.renderingClipId) return;
      this.batchStarting = true;
      this.renderError = null;
      const headers = { 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content };
      for (const c of this.unrenderedClips) {
        try {
          const res = await fetch(`/api/clips/${c.id}/render`, { method: 'POST', headers });
          const data = await res.json();
          if (!res.ok) throw new Error(data.message || 'HTTP ' + res.status);
          Object.assign(c, { status: data.status, error_msg: null });
        } catch (e) {
          Object.assign(c, { status: 'failed', error_msg: e.message });
        }
      }
      this.batchStarting = false;
      this.watchBatch();
    },
    // One poll of the project covers every clip in the batch
    watchBatch(delay = 4000) {
      clearTimeout(this.batchTimer);
      const projectId = this.projectId;
      const poll = async () => {
        if (this.projectId !== projectId || !this.clips.some(c => c.status === 'processing')) return;
        try {
          const res = await fetch(`/api/projects/${projectId}/status`, { headers: { 'Accept': 'application/json' } });
          const data = await res.json();
          if (this.projectId !== projectId) return;
          for (const fresh of data.clips || []) {
            const c = this.clips.find(x => x.id === fresh.id);
            if (c) Object.assign(c, { status: fresh.status, error_msg: fresh.error_msg || null });
          }
        } catch (e) {}
        if (this.clips.some(c => c.status === 'processing')) this.batchTimer = setTimeout(poll, 4000);
      };
      this.batchTimer = setTimeout(poll, delay);
    },
    // A finished clip opens its result straight away
    async openClipResult(c) {
      this.selectedClip = c.ranking;
      try {
        const res = await fetch(`/api/clips/${c.id}/render-status`, { headers: { 'Accept': 'application/json' } });
        const data = await res.json();
        if (data.status !== 'done') { c.status = data.status; return; }
        this.downloadUrl = data.download_url;
        this.musicMissing = !!data.music_missing;
        this.resultHookText = this.hookAi ? (c.hook_text || this.hookText) : this.hookText;
        this.done = true;
      } catch (e) {
        this.renderError = 'Koneksi gagal.';
      }
    },

    // ── trim a clip on its transcript ───────────────────────────────────────
    async openTrim(c) {
      this.trim = { open: true, clip: c, words: [], start: c.start_seconds, end: c.end_seconds, mode: 'start', loading: true, saving: false, error: null };
      try {
        const res = await fetch(`/api/clips/${c.id}/transcript`, { headers: { 'Accept': 'application/json' } });
        if (!res.ok) throw new Error('HTTP ' + res.status);
        const data = await res.json();
        if (this.trim.clip !== c) return; // another clip opened meanwhile
        this.trim.words = data.words;
        this.trim.loading = false;
        // bring the clip's first word into view
        this.$nextTick(() => this.$refs.trimWords?.querySelector('.trim-w.in')?.scrollIntoView({ block: 'center' }));
      } catch (e) {
        this.trim.loading = false;
        this.trim.error = 'Gagal memuat transkrip.';
      }
    },
    // First and last word of the clip get a marker
    trimEdge(i) {
      const w = this.trim.words, inside = j => w[j] && w[j].start >= this.trim.start - 0.01 && w[j].start < this.trim.end;
      return inside(i) && (!inside(i - 1) || !inside(i + 1));
    },
    pickTrimWord(i) {
      const w = this.trim.words, word = w[i];
      if (this.trim.mode === 'start') {
        // a breath before the word, never into the previous one
        const prevEnd = w[i - 1] && w[i - 1].end <= word.start ? w[i - 1].end : 0;
        this.trim.start = Math.max(prevEnd, word.start - 0.15);
        if (this.trim.end <= this.trim.start + 1) this.trim.end = word.end + 0.35;
        this.trim.mode = 'end';
      } else {
        const nextStart = w[i + 1] && w[i + 1].start >= word.end ? w[i + 1].start : Infinity;
        this.trim.end = Math.min(nextStart, word.end + 0.35);
        if (this.trim.end <= this.trim.start) this.trim.start = Math.max(0, word.start - 0.15);
      }
    },
    async saveTrim() {
      const c = this.trim.clip;
      this.trim.saving = true;
      this.trim.error = null;
      try {
        const res = await fetch(`/api/clips/${c.id}/bounds`, {
          method: 'PATCH',
          headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
          body: JSON.stringify({ start_seconds: +this.trim.start.toFixed(2), end_seconds: +this.trim.end.toFixed(2) }),
        });
        const data = await res.json();
        if (!res.ok) throw new Error(data.message || 'HTTP ' + res.status);
        Object.assign(c, { start_seconds: +data.start_seconds, end_seconds: +data.end_seconds, status: 'pending' });
        // the old render is gone: this clip renders afresh when picked
        if (this.selectedClip === c.ranking) this.downloadUrl = null;
        this.selectedClip = c.ranking;
        this.trim.open = false;
      } catch (e) {
        this.trim.error = e.message || 'Gagal menyimpan.';
      } finally {
        this.trim.saving = false;
      }
    },

    // ── utilities ──────────────────────────────────────────────────────────
    fmtClock(s) {
      s = Math.round(s);
      return String(Math.floor(s/60)).padStart(2,'0') + ':' + String(s%60).padStart(2,'0');
    },
    fmtDate(iso) {
      const d = new Date(iso), now = new Date(), diff = now - d;
      if (diff < 60000)    return 'Baru saja';
      if (diff < 3600000)  return Math.floor(diff / 60000) + ' menit lalu';
      if (diff < 86400000) return Math.floor(diff / 3600000) + ' jam lalu';
      return Math.floor(diff / 86400000) + ' hari lalu';
    },
    copyText(key, text) {
      try { navigator.clipboard?.writeText(text); } catch {}
      this.copied = key;
      setTimeout(() => { this.copied = ''; }, 1200);
    },
    // ── TikTok upload (inbox / draft) ──────────────────────────────────────
    get tiktokBusy() { return ['queued', 'uploading', 'processing'].includes(this.tiktokStatus); },
    get tiktokStatusLabel() {
      return {
        queued:     'Menunggu antrian…',
        uploading:  'Mengupload video ke TikTok…',
        processing: 'TikTok sedang memproses video…',
        inbox:      '✓ Terkirim ke inbox TikTok — buka app TikTok untuk menambah sound & posting.',
        published:  '✓ Sudah diposting di TikTok (cek profil kamu; sebelum app diaudit, videonya private).',
        failed:     'Upload gagal.',
      }[this.tiktokStatus] || '';
    },
    async loadMusicLibrary() {
      try {
        const res = await fetch('/api/music', { headers: { 'Accept': 'application/json' } });
        if (res.ok) { const d = await res.json(); this.musicLibrary = { total: d.total, counts: d.counts }; }
      } catch (e) { console.error('loadMusicLibrary', e); }
    },
    async loadTiktokAccounts() {
      try {
        const res = await fetch('/api/tiktok/accounts', { headers: { 'Accept': 'application/json' } });
        if (!res.ok) return;
        const data = await res.json();
        this.tiktokConfigured = data.configured;
        this.tiktokAccounts = data.accounts || [];
        if (!this.tiktokAccounts.some(a => a.id === this.tiktokAccountId)) {
          this.tiktokAccountId = this.tiktokAccounts[0]?.id ?? null;
        }
      } catch (e) { console.error('loadTiktokAccounts', e); }
    },
    get tiktokCanSubmit() {
      if (this.tiktokBusy || !this.tiktokAccountId) return false;
      if (this.tiktokMode === 'inbox') return true;
      return !!this.tiktokCreator && !!this.tiktokPrivacy;
    },
    async loadTiktokCreator() {
      this.tiktokCreator = null; this.tiktokCreatorError = null; this.tiktokPrivacy = '';
      if (this.tiktokMode !== 'direct' || !this.tiktokAccountId) return;
      if (!this.tiktokTitle && this.clipCaption) {
        this.tiktokTitle = (this.clipCaption + '\n\n' + this.clipHashtags.join(' ')).trim().slice(0, 2200);
      }
      this.tiktokCreatorLoading = true;
      try {
        const res = await fetch(`/api/tiktok/accounts/${this.tiktokAccountId}/creator-info`, { headers: { 'Accept': 'application/json' } });
        const data = await res.json();
        if (!res.ok) { this.tiktokCreatorError = data.message || 'Gagal membaca info akun TikTok.'; return; }
        this.tiktokCreator = data;
        // Interactions the creator switched off can't be enabled
        if (data.comment_disabled) this.tiktokAllowComment = false;
        if (data.duet_disabled)    this.tiktokAllowDuet = false;
        if (data.stitch_disabled)  this.tiktokAllowStitch = false;
      } catch (e) {
        this.tiktokCreatorError = 'Koneksi gagal.';
      } finally { this.tiktokCreatorLoading = false; }
    },
    async uploadToTiktok() {
      const clipId = this.selectedClipObj?.id;
      if (!clipId || !this.tiktokCanSubmit) return;
      this.tiktokError = null;
      const direct = this.tiktokMode === 'direct' ? {
        title: this.tiktokTitle,
        privacy_level: this.tiktokPrivacy,
        disable_comment: !this.tiktokAllowComment,
        disable_duet: !this.tiktokAllowDuet,
        disable_stitch: !this.tiktokAllowStitch,
      } : {};
      try {
        const res = await fetch(`/api/clips/${clipId}/tiktok`, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json', 'Accept': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
          },
          body: JSON.stringify({ account_id: this.tiktokAccountId, mode: this.tiktokMode, ...direct }),
        });
        const data = await res.json();
        if (!res.ok) { this.tiktokStatus = 'failed'; this.tiktokError = data.message || 'Gagal memulai upload.'; return; }
        this.applyTiktokStatus(data);
      } catch (e) {
        this.tiktokStatus = 'failed'; this.tiktokError = 'Koneksi gagal.';
      }
    },
    async fetchTiktokStatus() {
      const clipId = this.selectedClipObj?.id;
      if (!clipId) return;
      try {
        const res = await fetch(`/api/clips/${clipId}/tiktok-status`, { headers: { 'Accept': 'application/json' } });
        if (res.ok) this.applyTiktokStatus(await res.json());
      } catch (e) { console.error('fetchTiktokStatus', e); }
    },
    applyTiktokStatus(data) {
      if (data.clip_id !== this.selectedClipObj?.id) return;
      this.tiktokStatus = data.status;
      this.tiktokError = data.error;
      clearTimeout(this.tiktokTimer);
      if (this.tiktokBusy) this.tiktokTimer = setTimeout(() => this.fetchTiktokStatus(), 4000);
    },
    async fetchCaption() {
      const clipId = this.selectedClipObj?.id;
      if (!clipId) return;
      this.loadingCaption = true;
      try {
        const res = await fetch(`/api/clips/${clipId}/caption`, {
          headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content }
        });
        const data = await res.json();
        if (!res.ok) {
          console.error('fetchCaption error', res.status, data);
          return;
        }
        this.clipCaption  = data.caption  || '';
        this.clipHashtags = data.hashtags || [];
      } catch (e) {
        console.error('fetchCaption exception', e);
      }
      finally { this.loadingCaption = false; }
    },
    _uuid() {
      return crypto?.randomUUID ? crypto.randomUUID() : Math.random().toString(36).slice(2) + Date.now().toString(36);
    },
    restart() {
      if (this.generateTimer) clearTimeout(this.generateTimer);
      clearTimeout(this.renderTimer);
      this.clearSession();
      Object.assign(this, {
        step:1, maxReached:1, done:false, locked:false,
        url:'', meta:null, metaError:null,
        layout:'reframe',
        clipCount:3, durationMode:'auto', minDuration:20, maxDuration:45,
        generateStarted:false, generateProgress:0, generateLogIdx:0, generateError:null, generateLogMsg:null, generateCancelled:false,
        projectId:null,
        clips:[], selectedClip:null,
        renderingClipId:null, renderError:null, downloadUrl:null,
        clipCaption:'', clipHashtags:[],
      });
      this.previewT = 0;
    },

    // ── layout illustrations ───────────────────────────────────────────────
    layoutIllu(id, color) {
      const c = color;
      const wrap = (inner) => `<svg viewBox="0 0 96 96" width="100" height="100"><rect x="33" y="6" width="30" height="84" rx="6" fill="none" stroke="#34343f" stroke-width="1"/>${inner}</svg>`;
      if (id === 'reframe')  return wrap(`<rect x="35" y="8" width="26" height="80" rx="4" fill="#1a1a21"/><rect x="26" y="40" width="44" height="16" rx="1" fill="none" stroke="#34343f" stroke-width=".8" stroke-dasharray="2 2"/><rect x="40" y="34" width="16" height="28" rx="2" fill="none" stroke="${c}" stroke-width="1.5"/><path d="M40 34 L37 34 M40 34 L40 37" stroke="${c}" stroke-width="1.5"/><path d="M56 34 L59 34 M56 34 L56 37" stroke="${c}" stroke-width="1.5"/><circle cx="48" cy="44" r="4" fill="${c}"/><path d="M43 56 Q48 50 53 56" fill="none" stroke="${c}" stroke-width="1.4"/>`);
      if (id === 'gaussian') return wrap(`<defs><filter id="gf"><feGaussianBlur stdDeviation="3.5"/></filter><clipPath id="gc"><rect x="35" y="8" width="26" height="80" rx="4"/></clipPath><clipPath id="gcs"><rect x="35" y="40" width="26" height="16"/></clipPath></defs><rect x="35" y="8" width="26" height="80" rx="4" fill="#0d0d12"/><g clip-path="url(#gc)"><circle cx="41" cy="15" r="15" fill="${c}" opacity=".75" filter="url(#gf)"/><circle cx="59" cy="26" r="11" fill="${c}" opacity=".5" filter="url(#gf)"/><circle cx="46" cy="76" r="15" fill="${c}" opacity=".7" filter="url(#gf)"/><circle cx="59" cy="67" r="10" fill="${c}" opacity=".45" filter="url(#gf)"/></g><rect x="35" y="40" width="26" height="16" fill="#0a0a10"/><g clip-path="url(#gcs)"><path d="M35 56 L40 49 L44 52.5 L48.5 43 L53 50 L57 46 L61 56Z" fill="${c}" opacity=".35"/><rect x="35" y="40" width="26" height="6" fill="${c}" opacity=".07"/></g><rect x="35" y="40" width="26" height="16" fill="none" stroke="${c}" stroke-width="1" opacity=".85"/><line x1="35" y1="40" x2="61" y2="40" stroke="rgba(255,255,255,.2)" stroke-width=".7" stroke-dasharray="2 1.5"/><line x1="35" y1="56" x2="61" y2="56" stroke="rgba(255,255,255,.2)" stroke-width=".7" stroke-dasharray="2 1.5"/>`);
      return '';
    },

    // ── layout background for phone preview ────────────────────────────────
    layoutBg(id) {
      const thumb = (v) => {
        const hues = [205, 280, 160, 30];
        const h = hues[v % 4], h2 = (h + 50) % 360;
        const l1 = `oklch(0.34 0.07 ${h})`, l2 = `oklch(0.18 0.04 ${h2})`;
        return `<div style="width:100%;height:100%;background:linear-gradient(135deg,${l1},${l2});position:relative"><div style="position:absolute;inset:0;background:repeating-linear-gradient(-22deg,transparent,transparent 11px,rgba(255,255,255,.035) 11px,rgba(255,255,255,.035) 22px)"></div></div>`;
      };
      if (id === 'reframe')  return `<div style="position:absolute;inset:0;overflow:hidden"><div style="width:100%;height:100%;transform:scale(1.35);transform-origin:center 42%">${thumb(0)}</div></div>`;
      if (id === 'gaussian') return `<div style="position:absolute;inset:0;filter:blur(22px) saturate(2);transform:scale(1.5)">${thumb(1)}</div><div style="position:absolute;inset:0;background:rgba(0,0,0,.3)"></div><div style="position:absolute;left:0;right:0;top:50%;transform:translateY(-50%);aspect-ratio:16/9;overflow:hidden;outline:1px solid rgba(255,255,255,.28);box-shadow:0 0 0 1px rgba(0,0,0,.6),0 8px 30px rgba(0,0,0,.75)">${thumb(1)}</div>`;
      return '';
    },

    // ── init ───────────────────────────────────────────────────────────────
    async init() {
      this.loadSession();
      // The brand link on other pages (/?new=1) opens a fresh project
      if (new URLSearchParams(location.search).has('new')) {
        this.restart();
        history.replaceState(null, '', location.pathname);
      }
      this.watchAll();
      this.resumeIfNeeded();
      this.previewTick = setInterval(() => { this.previewT += 0.1; }, 100);
      // Web fonts change text widths; re-run the hook layout once they are in
      document.fonts?.ready.then(() => this.fontsTick++);
      document.fonts?.addEventListener?.('loadingdone', () => this.fontsTick++);
      await this.loadHistory();
      // Clear caption when done resets (new generation started)
      this.$watch('done', val => {
        if (!val) { this.clipCaption = ''; this.clipHashtags = []; return; }
        if (this.selectedClipObj) this.fetchCaption();
      });
      // Re-fetch caption when user switches to a different clip
      this.$watch('selectedClip', () => {
        this.clipCaption = ''; this.clipHashtags = [];
        this.tiktokStatus = null; this.tiktokError = null;
        if (this.done && this.selectedClipObj) { this.fetchCaption(); this.fetchTiktokStatus(); }
      });
      this.$watch('done', val => { if (val && this.selectedClipObj) this.fetchTiktokStatus(); });
      this.$watch('tiktokMode', () => this.loadTiktokCreator());
      this.$watch('tiktokAccountId', () => this.loadTiktokCreator());

      // Back from TikTok OAuth: /?tiktok=connected|denied|error|…
      const tiktokResult = new URLSearchParams(location.search).get('tiktok');
      if (tiktokResult) {
        history.replaceState(null, '', location.pathname);
        if (tiktokResult !== 'connected') alert('Gagal menghubungkan TikTok (' + tiktokResult + ').');
      }
      await Promise.all([this.loadTiktokAccounts(), this.loadMusicLibrary()]);
      if (this.done && this.selectedClipObj) this.fetchTiktokStatus();
      // If restored from session and already done
      if (this.done && this.selectedClipObj && !this.clipCaption) this.fetchCaption();
    },
  };
}
</script>
