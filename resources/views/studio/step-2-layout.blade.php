<div class="wizard fade-in" x-show="!done && step === 2">
  <div class="wiz-head">
    <div class="wiz-kicker">Langkah 2 dari 7</div>
    <h1 class="wiz-title">Pilih tipe layout</h1>
    <p class="wiz-desc">Tiap layout menentukan bagaimana video horizontal diubah jadi vertikal 9:16.</p>
  </div>

  {{-- One click sets layout, effects, subtitle, hook and music; every step stays editable --}}
  <div class="preset-bar" x-show="presets.length || presetsError">
    <div class="preset-bar-head">
      <span class="fblock-title" id="preset-bar-title">Mulai dari preset</span>
      <a class="rs-link" href="{{ route('presets.page') }}">Kelola preset</a>
    </div>
    <p class="trim-err" x-show="presetsError" style="margin:0">
      <span x-text="presetsError"></span> <button class="rs-link" type="button" @click="loadPresets()">Coba lagi</button>
    </p>
    <div class="preset-chips" role="group" aria-labelledby="preset-bar-title">
      <template x-for="p in presets" :key="p.id">
        <button type="button" class="preset-chip" :class="{ on: presetId === p.id }" :aria-pressed="presetId === p.id"
                @click="applyPreset(p)" :title="p.summary.join(' · ')">
          <span class="preset-chip-name" x-text="p.name"></span>
          <span class="preset-chip-sub" x-text="p.summary.join(' · ')"></span>
          <span class="preset-chip-tag" x-show="p.id === presetDefaultId">Default</span>
        </button>
      </template>
    </div>
    <p class="fhint preset-note" x-show="presetStatus" x-text="presetStatus"></p>
  </div>
  <div class="layout-grid">
    <template x-for="L in LAYOUTS" :key="L.id">
      <div class="layout-item">
      <button class="layout-card" :class="['c-'+L.color, layout === L.id ? 'lsel' : '']"
              :disabled="L.ready === false" @click="if (L.ready !== false) layout = L.id" type="button">
        <div class="layout-card-meta">
          <div class="layout-card-badges">
            <span x-show="L.badge" :class="['badge', L.badge ? L.badge.cls : '']" x-text="L.badge ? L.badge.text : ''"></span>
            <span style="font-family:var(--font-mono);font-size:10px;color:var(--text-dim);letter-spacing:.06em;text-transform:uppercase" x-text="L.sub"></span>
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
      <div class="layout-card-lock" x-show="L.ready === false">Belum ada footage. <a href="{{ route('gameplay.page') }}" target="_blank" rel="noopener">Unggah footage gameplay-mu</a> di Pengaturan, lalu muat ulang halaman ini.</div>
      </div>
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
      <button class="tgl" :class="{ on: sfx }" @click="sfx = !sfx" type="button">
        <span class="tgl-track"></span>
        <span class="tgl-text">Efek suara otomatis</span>
      </button>
      <div class="fhint" style="margin-top:8px">Whoosh saat B-roll masuk, pop saat emoji muncul, bunyi pendek di punchline. Potongan dan zoom tetap tanpa suara.</div>
      <div class="sfx-style" x-show="sfx" x-cloak>
        <div class="seg" role="group" aria-label="Gaya efek suara">
          <button type="button" :class="{ on: sfxStyle === 'subtle' }" :aria-pressed="sfxStyle === 'subtle'" @click="sfxStyle = 'subtle'">Halus</button>
          <button type="button" :class="{ on: sfxStyle === 'energetic' }" :aria-pressed="sfxStyle === 'energetic'" @click="sfxStyle = 'energetic'">Energik</button>
        </div>
        <div class="fhint" x-text="sfxStyle === 'energetic' ? 'Sampai 7 suara per clip, lebih keras, plus whoosh saat hook selesai.' : 'Paling banyak 4 suara per clip, pelan di bawah suara pembicara.'"></div>
      </div>
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
