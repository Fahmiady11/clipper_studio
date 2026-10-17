<div class="wizard fade-in" x-show="!done && step === 7">
  <div class="wiz-head">
    <div class="wiz-kicker">Langkah 7 dari 7</div>
    <h1 class="wiz-title">Pilih clip untuk dirender</h1>
    <p class="wiz-desc" x-text="'AI menemukan ' + clips.length + ' momen. Pilih satu untuk dipotong, diberi layout, subtitle, dan hook.'"></p>
  </div>

  <div class="render-card" x-show="renderingClipId">
    <div class="render-card-spin"></div>
    <div class="render-card-txt">
      <b x-text="'Merender clip #' + (selectedClipObj ? selectedClipObj.ranking : '') + '…'"></b>
      <span>Potong, layout, subtitle, hook, lalu encode. Biasanya 1–5 menit.</span>
    </div>
    <button class="btn btn-secondary btn-sm" type="button" @click="cancelRender()" :disabled="cancelling"
            x-text="cancelling ? 'Membatalkan…' : 'Batalkan'"></button>
  </div>

  <div class="batch-bar" x-show="clips.length > 1 && !renderingClipId && (unrenderedClips.length > 0 || batchRendering || doneClips >= 2)">
    <span x-show="!batchRendering && unrenderedClips.length > 0" x-text="unrenderedClips.length + ' clip belum dirender. Render semuanya sekaligus, hasilnya bisa dibuka satu per satu.'"></span>
    <span x-show="batchRendering" x-text="'Merender ' + clips.filter(c => c.status === 'processing').length + ' clip… boleh tinggalkan halaman ini.'"></span>
    <span x-show="!batchRendering && unrenderedClips.length === 0" x-text="'Semua ' + doneClips + ' clip sudah dirender.'"></span>
    <div class="batch-actions">
      <a class="btn btn-secondary btn-sm" x-show="doneClips >= 2" :href="'/api/projects/' + projectId + '/download'"
         x-text="'Download semua (' + doneClips + ') · ZIP'"></a>
      <button class="btn btn-secondary btn-sm" type="button" x-show="unrenderedClips.length > 0" @click="renderAll()" :disabled="batchStarting"
              x-text="batchStarting ? 'Memulai…' : 'Render semua (' + unrenderedClips.length + ')'"></button>
    </div>
  </div>

  <div x-show="renderError" style="margin-bottom:16px;padding:12px 16px;background:var(--danger-soft);border:1px solid var(--danger);border-radius:10px;font-size:13.5px;color:var(--danger)" x-text="renderError"></div>

  <div class="clip-list">
    <template x-for="c in clips" :key="c.ranking">
      <button class="clip-card-s" :class="{ on: selectedClip === c.ranking }" @click="!renderingClipId && (selectedClip = c.ranking)" :disabled="!!renderingClipId && selectedClip !== c.ranking" type="button">
        <div class="clip-rank" x-text="c.ranking"></div>
        <div>
          <div class="clip-topic" x-text="c.topic"></div>
          <div class="clip-reason" x-text="c.reason"></div>
          <div class="clip-time">
            <span x-text="'⧗ ' + fmtClock(c.start_seconds) + ' → ' + fmtClock(c.end_seconds)"></span>
            <span x-text="'durasi ' + Math.round(c.end_seconds - c.start_seconds) + 's'"></span>
            <span class="clip-trim" role="button" tabindex="0" x-show="!renderingClipId"
                  @click.stop="openTrim(c)" @keydown.enter.stop.prevent="openTrim(c)">✂ Atur awal/akhir</span>
          </div>
        </div>
        <div class="clip-aside">
          <span class="viral" :class="c.viral_potential" x-text="'● ' + c.viral_potential"></span>
          <span class="clip-state" x-show="c.status === 'processing' && renderingClipId !== c.id">⏳ Diproses</span>
          <span class="clip-state is-fail" x-show="c.status === 'failed'" :title="c.error_msg || ''">Gagal</span>
          <span class="clip-state is-done" role="button" tabindex="0" x-show="c.status === 'done'"
                @click.stop="openClipResult(c)" @keydown.enter.stop.prevent="openClipResult(c)">✓ Lihat hasil</span>
          <span class="clip-pick" x-text="selectedClip === c.ranking ? '✓ Dipilih' : 'Pilih clip ini'"></span>
        </div>
      </button>
    </template>
  </div>
</div>
