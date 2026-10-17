<div class="wizard fade-in" x-show="!done && step === 6">

  {{-- Not started --}}
  <div class="processing" x-show="!generateStarted">
    <div class="proc-status" style="color:var(--text-muted)" x-text="generateCancelled ? 'dibatalkan' : 'siap dianalisis'"></div>
    <div class="proc-note" x-show="generateCancelled">Analisis sebelumnya dibatalkan. Ubah pengaturan lewat langkah di atas, atau generate ulang.</div>
    <h2 class="proc-title">Generate clip dengan AI</h2>
    <p class="proc-sub">
      Gemini akan membaca transcript dan memilih <b style="color:var(--text)" x-text="clipCount"></b> momen paling
      berpotensi viral, lalu menyusun subtitle dan hook otomatis.
    </p>
    <button class="btn" style="margin-top:28px;font-size:15px;padding:16px 28px" @click="startGenerate()">
      Generate Clip dengan AI
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M5 3l4 9-4 9 14-9z"/></svg>
    </button>
    <div style="margin-top:18px;font-family:var(--font-mono);font-size:11px;color:var(--text-dim)">
      model: gemini-2.5-flash · subtitle: groq-whisper
    </div>
  </div>

  {{-- Error --}}
  <div x-show="generateError" class="gen-error">
    <div x-text="generateError"></div>
    <div class="gen-error-actions" x-show="projectId">
      <button class="btn btn-sm" type="button" @click="retryGenerate()" :disabled="retrying" x-text="retrying ? 'Mengulang…' : 'Coba lagi'"></button>
      <span>Video & transcript sudah tersimpan — hanya langkah AI yang diulang.</span>
    </div>
  </div>

  {{-- Running --}}
  <div class="processing" x-show="generateStarted && !generateError">
    <div class="proc-status" x-text="'analyzing · ' + generateProgress + '%'"></div>
    <h2 class="proc-title">AI sedang menganalisis video…</h2>
    <p class="proc-sub" x-text="'Memilih ' + clipCount + ' momen terbaik.'"></p>
    <div class="proc-bar-wrap"><div class="proc-bar" :style="'width:' + generateProgress + '%'"></div></div>
    <button class="btn btn-secondary proc-cancel" type="button" @click="cancelGenerate()" :disabled="cancelling || !projectId"
            x-text="cancelling ? 'Membatalkan…' : 'Batalkan analisis'"></button>
    <div class="proc-log">
      <template x-for="(s, i) in STAGES" :key="i">
        <div class="proc-log-row">
          {{-- Real message from backend if this stage is active/done, else label --}}
          <span class="proc-log-msg"
                :style="(i + 1) > generateLogIdx ? 'opacity:.35' : ''"
                x-text="(i + 1) === generateLogIdx && generateLogMsg
                          ? generateLogMsg
                          : s.msg.replace('N momen', clipCount + ' momen')">
          </span>
          <span class="proc-log-state"
                :class="(i + 1) < generateLogIdx ? 'ok' : (i + 1) === generateLogIdx ? 'run' : 'wait'"
                x-text="(i + 1) < generateLogIdx ? '✓ DONE' : (i + 1) === generateLogIdx ? '● RUN' : '○ WAIT'">
          </span>
        </div>
      </template>
    </div>
  </div>

</div>
