<div class="modal-bg" x-show="trim.open" @click="trim.open = false" @keydown.escape.window="trim.open = false">
  <div class="modal modal-trim" @click.stop>
    <h3 class="modal-title">Atur awal &amp; akhir clip</h3>
    <p class="modal-sub">Klik kata pertama, lalu kata terakhir. Teks tebal = isi clip.</p>

    <div class="trim-bar">
      <div class="seg">
        <button :class="{ on: trim.mode === 'start' }" @click="trim.mode = 'start'" type="button">Pilih awal</button>
        <button :class="{ on: trim.mode === 'end' }" @click="trim.mode = 'end'" type="button">Pilih akhir</button>
      </div>
      <span class="trim-info" x-show="!trim.loading" x-text="fmtClock(trim.start) + ' → ' + fmtClock(trim.end) + ' · ' + (trim.end - trim.start).toFixed(1) + ' detik'"></span>
    </div>

    <div class="trim-words" x-ref="trimWords">
      <div x-show="trim.loading" class="trim-muted">Memuat transkrip…</div>
      <div x-show="!trim.loading && trim.words.length === 0" class="trim-muted">Transkrip tidak tersedia untuk clip ini.</div>
      <template x-for="(w, i) in trim.words" :key="i">
        <span class="trim-w" :class="{ in: w.start >= trim.start - 0.01 && w.start < trim.end, edge: trimEdge(i) }"
              @click="pickTrimWord(i)" x-text="w.text + ' '"></span>
      </template>
    </div>

    <div x-show="trim.error" class="trim-err" x-text="trim.error"></div>
    <div class="modal-actions">
      <button class="btn btn-secondary" type="button" @click="trim.open = false">Batal</button>
      <button class="btn" type="button" @click="saveTrim()" :disabled="trim.saving || trim.loading || trim.end - trim.start < 3"
              x-text="trim.saving ? 'Menyimpan…' : 'Simpan'"></button>
    </div>
  </div>
</div>
