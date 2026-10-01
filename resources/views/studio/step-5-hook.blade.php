<div class="wizard fade-in" x-show="!done && step === 5">
  <div class="wiz-head">
    <div class="wiz-kicker">Langkah 5 dari 7</div>
    <h1 class="wiz-title">Pengaturan hook</h1>
    <p class="wiz-desc">Hook pembuka 2–5 detik untuk menahan penonton di 3 detik pertama.</p>
  </div>
  <div class="wiz-split">
    <div>
      <div class="fblock">
        <button class="tgl" :class="{ on: hookEnabled }" @click="hookEnabled = !hookEnabled" type="button">
          <span class="tgl-track"></span>
          <span class="tgl-text">Aktifkan hook pembuka</span>
        </button>
      </div>
      <div class="settings-group" :class="{ off: !hookEnabled }">
        <div class="fblock">
          <div class="fblock-head">
            <div class="fblock-title">Teks hook</div>
            <button class="tgl" :class="{ on: hookAi }" @click="hookAi = !hookAi" type="button">
              <span class="tgl-track"></span>
              <span class="tgl-text" style="font-size:12px">Generate otomatis (AI)</span>
            </button>
          </div>
          <textarea class="field" maxlength="100" :disabled="hookAi"
                    :value="hookAi ? 'AI akan menulis hook berdasarkan isi clip…' : hookText"
                    @input="hookText = $event.target.value"
                    :style="hookAi ? 'opacity:.5' : ''"></textarea>
          <div class="field-count" x-show="!hookAi" x-text="hookText.length + '/100'"></div>
        </div>
        <div class="fblock">
          <div class="fblock-title" style="margin-bottom:10px">Durasi tampil</div>
          <div class="sld-wrap">
            <input class="sld" type="range" min="2" max="5" step="0.5" x-model.number="hookDuration" />
            <span class="sld-val" x-text="hookDuration + 's'"></span>
          </div>
        </div>
        <div class="fblock">
          <div class="fblock-title" style="margin-bottom:10px">Posisi</div>
          <div class="seg">
            <button :class="{ on: hookPos === 'top' }"    @click="hookPos = 'top'"    type="button">Atas</button>
            <button :class="{ on: hookPos === 'center' }" @click="hookPos = 'center'" type="button">Tengah</button>
            <button :class="{ on: hookPos === 'bottom' }" @click="hookPos = 'bottom'" type="button">Bawah</button>
          </div>
        </div>
        <div class="fblock">
          <div class="fblock-title" style="margin-bottom:10px">Gaya</div>
          <div class="hook-styles">
            <button class="hook-style" :class="{ on: hookBg === 'full' }" @click="hookBg = 'full'" type="button">
              <span class="hook-style-demo hs-full"><i>Label</i><i>TikTok</i></span>
              <span class="hook-style-name">Label</span>
            </button>
            <button class="hook-style" :class="{ on: hookBg === 'semi' }" @click="hookBg = 'semi'" type="button">
              <span class="hook-style-demo hs-semi">Kotak<br>gelap</span>
              <span class="hook-style-name">Kotak</span>
            </button>
            <button class="hook-style" :class="{ on: hookBg === 'none' }" @click="hookBg = 'none'" type="button">
              <span class="hook-style-demo hs-none">Teks<br>outline</span>
              <span class="hook-style-name">Outline</span>
            </button>
          </div>
        </div>
        <div class="fblock">
          <div class="fblock-title" style="margin-bottom:10px" x-text="hookBg === 'full' ? 'Warna label' : 'Warna teks'"></div>
          <div class="swatches">
            <template x-for="c in COLOR_PRESETS" :key="c">
              <button class="swatch" :class="{ on: hookTextColor === c }" :style="'background:'+c" @click="hookTextColor = c" type="button"></button>
            </template>
          </div>
        </div>
        <div class="fhint">Apit kata dengan <b>*bintang*</b> untuk menyorotnya dengan warna highlight subtitle, misalnya <code>Gaji 50 juta, tetap *bokek*</code>. Hook pendek (3–7 kata) paling kuat; ukuran teks menyesuaikan otomatis. Font mengikuti subtitle (<span x-text="subtitleFont"></span>).</div>
      </div>

      <div class="fblock" style="margin-top:28px">
        <button class="tgl" :class="{ on: musicEnabled }" @click="musicEnabled = !musicEnabled" type="button">
          <span class="tgl-track"></span>
          <span class="tgl-text">Tambahkan musik latar</span>
        </button>
      </div>
      <div class="settings-group" :class="{ off: !musicEnabled }">
        <div class="fblock">
          <div class="fblock-title" style="margin-bottom:10px">Mood musik</div>
          <div class="seg" style="flex-wrap:wrap">
            <button :class="{ on: musicMood === '' }" @click="musicMood = ''" type="button">Otomatis (AI)</button>
            <template x-for="m in MUSIC_MOODS" :key="m.id">
              <button :class="{ on: musicMood === m.id }" @click="musicMood = m.id" type="button" x-text="m.label"></button>
            </template>
          </div>
        </div>
        <div class="fblock">
          <div class="fblock-title" style="margin-bottom:10px">Volume musik</div>
          <div class="sld-wrap">
            <input class="sld" type="range" min="5" max="100" step="1" x-model.number="musicVolume" />
            <span class="sld-val" x-text="musicVolume + '%'"></span>
          </div>
          <div style="margin-top:8px;font-size:12px;color:var(--text-dim)">15% = jelas di belakang suara · 30% = sejajar suara. Musik sedikit mengecil saat ada orang bicara.</div>
          <div style="margin-top:8px;font-size:12px;color:var(--text-dim)">Lagu diambil dari <a href="/music" style="color:var(--teal)">library musik</a> sesuai mood; kredit lagu CC BY otomatis masuk caption.</div>
          <div class="music-warn" x-show="musicEnabled && musicLibrary && musicLibrary.total === 0">
            Library musik masih kosong — clip akan dirender <b>tanpa musik</b>. <a href="/music">Upload lagu dulu →</a>
          </div>
          <div class="music-warn soft" x-show="musicEnabled && musicLibrary && musicLibrary.total > 0 && musicMood && !musicLibrary.counts[musicMood]">
            Belum ada lagu untuk mood ini — akan dipakai lagu dari mood lain.
          </div>
        </div>
      </div>
    </div>
    <div class="wiz-preview">
      <div class="preview-label"><span>Live preview · 9:16</span><span x-text="currentLayout.title"></span></div>
      @include('studio._phone-preview')
      <div style="margin-top:12px;text-align:center;font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--text-dim);letter-spacing:.05em">↻ Hook tampil di awal loop</div>
    </div>
  </div>
</div>
