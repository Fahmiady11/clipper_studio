<div class="wizard narrow fade-in" x-show="!done && step === 1">
  <div class="wiz-head">
    <div class="wiz-kicker">Langkah 1 dari 7</div>
    <h1 class="wiz-title">Tempel link YouTube</h1>
    <p class="wiz-desc">Masukkan URL video yang ingin kamu jadikan konten clipper.</p>
  </div>

  <div class="url-card" :class="{ valid: url && isValidUrl, invalid: url && !isValidUrl }">
    <div class="url-icon">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
    </div>
    <input class="url-input" type="text" x-model="url"
           placeholder="youtube.com/watch?v=…  ·  youtu.be/…  ·  /shorts/…"
           @input="meta = null"
           @keydown.enter="processLink()" />
    <button class="btn" style="padding:11px 18px;border-radius:14px"
            x-show="url && !meta"
            :disabled="!isValidUrl || loadingMeta"
            @click="processLink()"
            x-text="loadingMeta ? 'Memproses…' : 'Proses Link'"></button>
  </div>
  <div class="url-hint">
    <span>Didukung:</span>
    <code>youtube.com/watch?v=…</code><code>youtu.be/…</code><code>youtube.com/shorts/…</code>
  </div>

  <div x-show="metaError" style="margin-top:14px;padding:12px 16px;background:var(--danger-soft);border:1px solid var(--danger);border-radius:10px;font-size:13.5px;color:var(--danger)" x-text="metaError"></div>

  <div class="meta-card" x-show="meta">
    <div class="meta-thumb">
      <img x-show="meta?.thumbnailUrl" :src="meta?.thumbnailUrl" :alt="meta?.title"
           style="width:100%;height:100%;object-fit:cover;display:block" />
      <div x-show="!meta?.thumbnailUrl" style="width:100%;height:100%;background:var(--surface-3)"></div>
    </div>
    <div class="meta-body">
      <h3 class="meta-vtitle" x-text="meta?.title"></h3>
      <div class="meta-rows">
        <div class="meta-row">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
          Durasi <b x-text="meta ? fmtClock(meta.durationSeconds) : ''"></b>
        </div>
        <div class="meta-row">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M2 12h20M12 2a15 15 0 0 1 0 20M12 2a15 15 0 0 0 0 20"/><circle cx="12" cy="12" r="10"/></svg>
          Bahasa audio <b x-text="meta?.language"></b>
        </div>
        <div class="meta-row">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M4 6h16M4 12h16M4 18h10"/></svg>
          Channel <b x-text="meta?.channel"></b>
        </div>
      </div>
      <span class="meta-ok">✓ METADATA BERHASIL DIAMBIL</span>
    </div>
  </div>

  {{-- Optional brief for the AI: which moments to look for --}}
  <div class="instr" x-show="meta">
    <label class="fblock-title" for="instr-input">Cari bagian tertentu? <span class="instr-opt">opsional</span></label>
    <p class="fblock-hint">Tulis apa yang kamu cari, AI memilih clip yang sesuai. Kosongkan untuk momen paling berpotensi viral.</p>
    <textarea id="instr-input" class="field" rows="2" maxlength="300" x-model="instruction"
              placeholder="mis. ambil bagian tentang modal usaha"></textarea>
    <div class="instr-foot">
      <div class="instr-eg">
        <span>Contoh:</span>
        <template x-for="ex in INSTRUCTION_EXAMPLES" :key="ex">
          <button type="button" class="instr-chip" @click="instruction = ex" x-text="ex"></button>
        </template>
      </div>
      <span class="field-count" x-text="instruction.length + '/300'"></span>
    </div>
  </div>
</div>
