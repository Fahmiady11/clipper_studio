<div x-show="!done && step !== 6" class="wiz-nav-wrap">
  <div class="wiz-nav">
    <div class="wiz-nav-summary">
      <span x-show="step === 1" x-text="meta ? 'Video siap · ' + fmtClock(meta.durationSeconds) : 'Proses link untuk lanjut'"></span>
      <span x-show="step === 2">Layout: <b x-text="currentLayout.title"></b></span>
      <span x-show="step === 3"><b x-text="clipCount"></b> clip · <span x-text="durationMode === 'auto' ? 'durasi otomatis' : minDuration + '–' + maxDuration + 's'"></span></span>
      <span x-show="step === 4">Subtitle <b x-text="subtitleEnabled ? 'aktif' : 'nonaktif'"></b><span x-show="subtitleEnabled"> · <span x-text="subtitleFont"></span></span></span>
      <span x-show="step === 5">Hook <b x-text="hookEnabled ? 'aktif' : 'nonaktif'"></b><span x-show="hookEnabled"> · <span x-text="hookDuration + 's'"></span></span></span>
      <span x-show="step === 7" x-text="selectedClip ? 'Clip #' + selectedClip + ' dipilih' : 'Pilih satu clip untuk dirender'"></span>
    </div>
    <div class="wiz-nav-btns">
      <span x-show="locked && !done" class="locked-notice">🔒 Terkunci setelah generate</span>
      <button class="btn btn-ghost" x-show="step > 1 && (!locked || done)" @click="back()">← Kembali</button>
      <button class="btn" x-show="step < 7" :disabled="!canNext" @click="next()">
        <span x-text="step === 5 ? 'Lanjut ke Generate' : 'Lanjut'"></span>
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
      </button>
      <button class="btn" x-show="step === 7" :disabled="!selectedClip || !!renderingClipId"
              @click="renderClip()">
        <span x-text="renderingClipId ? 'Merender…' : (selectedClipObj && selectedClipObj.status === 'done' ? 'Lihat hasil' : 'Render clip ini')"></span>
        <svg x-show="!renderingClipId" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 3l4 9-4 9 14-9z"/></svg>
      </button>
    </div>
  </div>
</div>
