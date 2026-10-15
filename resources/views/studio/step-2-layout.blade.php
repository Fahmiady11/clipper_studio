<div class="wizard fade-in" x-show="!done && step === 2">
  <div class="wiz-head">
    <div class="wiz-kicker">Langkah 2 dari 7</div>
    <h1 class="wiz-title">Pilih tipe layout</h1>
    <p class="wiz-desc">Tiap layout menentukan bagaimana video horizontal diubah jadi vertikal 9:16.</p>
  </div>
  <div class="layout-grid">
    <template x-for="L in LAYOUTS" :key="L.id">
      <button class="layout-card" :class="['c-'+L.color, layout === L.id ? 'lsel' : '']"
              @click="layout = L.id" type="button">
        <div class="layout-card-meta">
          <div class="layout-card-badges">
            <span x-show="L.badge" :class="['badge', L.badge ? L.badge.cls : '']" x-text="L.badge ? L.badge.text : ''"></span>
            <span style="font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--text-dim);letter-spacing:.06em;text-transform:uppercase" x-text="L.sub"></span>
          </div>
          <div class="layout-card-title" x-text="L.title"></div>
          <div class="layout-card-desc" x-text="L.desc"></div>
          <div style="font-size:11.5px;color:var(--text-dim);margin-top:2px">
            <span :style="'color:'+L.accent">Cocok:</span> <span x-text="L.good"></span>
          </div>
        </div>
        <div class="layout-card-illu" x-html="layoutIllu(L.id, L.accent)"></div>
        <span class="layout-card-check">✓ Dipilih</span>
      </button>
    </template>
  </div>
  <div class="edit-opts">
    <div class="fblock">
      <button class="tgl" :class="{ on: jumpCut }" @click="jumpCut = !jumpCut" type="button">
        <span class="tgl-track"></span>
        <span class="tgl-text">Potong jeda &amp; kata pengisi</span>
      </button>
      <div class="fhint" style="margin-top:8px">Jeda diam dan "eh", "hmm" dipotong, jadi clip terasa cepat seperti diedit manual.</div>
    </div>
    <div class="fblock">
      <button class="tgl" :class="{ on: autoZoom }" @click="autoZoom = !autoZoom" type="button">
        <span class="tgl-track"></span>
        <span class="tgl-text">Zoom otomatis</span>
      </button>
      <div class="fhint" style="margin-top:8px">Gambar sedikit zoom-in dan kembali di potongan dan akhir kalimat, supaya tidak monoton.</div>
    </div>
    <div class="fblock">
      <button class="tgl" :class="{ on: progressBar }" @click="progressBar = !progressBar" type="button">
        <span class="tgl-track"></span>
        <span class="tgl-text">Progress bar</span>
      </button>
      <div class="fhint" style="margin-top:8px">Garis tipis di tepi atas yang terisi sampai clip selesai, bikin penonton bertahan sampai akhir.</div>
    </div>
    <div class="fblock">
      <button class="tgl" :class="{ on: topTitle }" @click="topTitle = !topTitle" type="button">
        <span class="tgl-track"></span>
        <span class="tgl-text">Judul di atas</span>
      </button>
      <div class="fhint" style="margin-top:8px">Topik clip dari AI tampil terus di bagian atas setelah hook selesai.</div>
    </div>
    <div class="fblock">
      <button class="tgl" :class="{ on: broll }" @click="broll = !broll" type="button">
        <span class="tgl-track"></span>
        <span class="tgl-text">B-roll otomatis</span>
      </button>
      <div class="fhint" style="margin-top:8px">AI menyisipkan cuplikan video stok 2 detik saat membahas benda, tempat, atau aksi (mis. uang, kota, olahraga).
        @unless (config('services.pexels.key') || config('services.pixabay.key'))<b>Belum aktif:</b> isi <code>PIXABAY_API_KEY</code> atau <code>PEXELS_API_KEY</code> di .env.@endunless</div>
    </div>
  </div>
</div>
