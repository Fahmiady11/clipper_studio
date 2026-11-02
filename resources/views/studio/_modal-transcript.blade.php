{{-- Cut a clip by hand: pick the first and last caption line; the server snaps them to sentence ends --}}
<div class="modal-bg" x-show="tx.open" @click="tx.open = false" @keydown.escape.window="tx.open && (tx.open = false)">
  <div class="modal modal-trim modal-tx" @click.stop role="dialog" aria-modal="true" aria-labelledby="tx-title">
    <h3 class="modal-title" id="tx-title">Buat clip dari transkrip</h3>
    <p class="modal-sub">Klik kalimat pertama, lalu kalimat terakhir. Awal dan akhirnya dirapikan ke batas kalimat. Judul dan hook ditulis AI.</p>

    <div class="trim-bar">
      <div class="seg">
        <button :class="{ on: tx.mode === 'start' }" @click="tx.mode = 'start'" type="button">Pilih awal</button>
        <button :class="{ on: tx.mode === 'end' }" @click="tx.mode = 'end'" type="button" :disabled="tx.startIdx === null">Pilih akhir</button>
      </div>
      <span class="trim-info" x-show="tx.startIdx !== null" x-text="fmtClock(txStart) + ' → ' + fmtClock(txEnd) + ' · ' + txDuration.toFixed(1) + ' detik'"></span>
    </div>

    <div class="tx-search">
      <input class="field" type="search" x-model="tx.q" @input="tx.hitAt = -1" @keydown.enter.prevent="txFindNext()"
             placeholder="Cari kata, mis. modal" aria-label="Cari di transkrip">
      <button class="btn btn-ghost btn-sm" type="button" @click="txFindNext()" :disabled="!txHits.length"
              x-text="tx.q.trim().length < 2 ? 'Cari' : (txHits.length ? 'Berikutnya (' + txHits.length + ')' : 'Tidak ada')"></button>
    </div>

    <div class="tx-lines" x-ref="txLines">
      <div x-show="tx.loading" class="trim-muted">Memuat transkrip…</div>
      <div x-show="tx.error" class="trim-err" x-text="tx.error"></div>
      <div x-show="!tx.loading && !tx.error && tx.lines.length === 0" class="trim-muted">Transkrip tidak tersedia untuk video ini.</div>
      <template x-for="(l, i) in tx.lines" :key="i">
        <button type="button" class="tx-line" @click="pickTxLine(i)"
                :class="{ in: txIn(i), edge: i === tx.startIdx || i === tx.endIdx, hit: txHit(l), taken: !!txTaken(l) }"
                :aria-pressed="txIn(i)" :title="txTaken(l) ? 'Sudah ada di clip #' + txTaken(l).ranking : ''">
          <span class="tx-time" x-text="fmtClock(l.start)"></span>
          <span class="tx-text" x-text="l.text"></span>
        </button>
      </template>
    </div>
    <p class="tx-legend" x-show="tx.lines.length">Garis di kiri: bagian yang sudah jadi clip.</p>

    <div class="trim-err" x-show="txProblem" x-text="txProblem"></div>
    <div class="trim-err" x-show="tx.saveError" role="alert" x-text="tx.saveError"></div>
    <div class="modal-actions">
      <span class="tx-cost" x-show="txCanCreate" x-text="'Memakai ±' + Math.ceil(txDuration) + ' detik kuota render'"></span>
      <button class="btn btn-secondary" type="button" @click="tx.open = false">Batal</button>
      <button class="btn" type="button" @click="createManualClip()" :disabled="!txCanCreate"
              x-text="tx.saving ? 'Membuat…' : 'Buat clip & render'"></button>
    </div>
  </div>
</div>
