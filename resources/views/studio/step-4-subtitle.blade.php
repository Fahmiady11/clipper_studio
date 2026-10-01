<div class="wizard fade-in" x-show="!done && step === 4">
  <div class="wiz-head">
    <div class="wiz-kicker">Langkah 4 dari 7</div>
    <h1 class="wiz-title">Pengaturan subtitle</h1>
    <p class="wiz-desc">Subtitle karaoke — kata yang sedang diucapkan disorot dengan warna berbeda.</p>
  </div>
  <div class="wiz-split">

    {{-- LEFT: controls --}}
    <div>
      <div class="fblock">
        <button class="tgl" :class="{ on: subtitleEnabled }" @click="subtitleEnabled = !subtitleEnabled" type="button">
          <span class="tgl-track"></span>
          <span class="tgl-text">Aktifkan subtitle</span>
        </button>
      </div>
      <div class="settings-group" :class="{ off: !subtitleEnabled }">
        <div class="fblock">
          <div class="fblock-title" style="margin-bottom:10px">Font</div>
          <select class="select" x-model="subtitleFont">
            <template x-for="f in FONTS" :key="f"><option :value="f" x-text="f"></option></template>
          </select>
        </div>
        <div class="fblock">
          <div class="fblock-title" style="margin-bottom:10px">Ukuran font</div>
          <div class="sld-wrap">
            <input class="sld" type="range" min="24" max="72" x-model.number="subtitleSize" />
            <span class="sld-val" x-text="subtitleSize + 'px'"></span>
          </div>
        </div>
        <div class="fblock">
          <div class="fblock-title" style="margin-bottom:10px">Warna teks utama</div>
          <div class="swatches">
            <template x-for="c in COLOR_PRESETS" :key="c">
              <button class="swatch" :class="{ on: subtitleTextColor === c }" :style="'background:'+c" @click="subtitleTextColor = c" type="button"></button>
            </template>
          </div>
        </div>
        <div class="fblock">
          <div class="fblock-title" style="margin-bottom:10px">
            Warna highlight
            <span style="color:var(--text-dim);font-weight:400;font-size:12px">· kata aktif</span>
          </div>
          <div class="swatches">
            <template x-for="c in HL_PRESETS" :key="c">
              <button class="swatch" :class="{ on: subtitleHighlight === c }" :style="'background:'+c" @click="subtitleHighlight = c" type="button"></button>
            </template>
          </div>
        </div>
        <div class="fblock">
          <div class="fblock-title" style="margin-bottom:10px">Posisi</div>
          <div class="seg">
            <button :class="{ on: subtitleY === null && subtitlePos === 'top' }"    @click="subtitlePos = 'top'; subtitleY = null"    type="button">Atas</button>
            <button :class="{ on: subtitleY === null && subtitlePos === 'center' }" @click="subtitlePos = 'center'; subtitleY = null" type="button">Tengah</button>
            <button :class="{ on: subtitleY === null && subtitlePos === 'bottom' }" @click="subtitlePos = 'bottom'; subtitleY = null" type="button">Bawah</button>
            <button class="on" x-show="subtitleY !== null" @click="subtitleY = null" type="button" title="Kembali ke preset">
              <span x-text="'Kustom · ' + subtitleY + '%'"></span> ✕
            </button>
          </div>
          <div class="fhint" style="margin-top:8px">Atau seret subtitle di preview untuk menaruhnya persis di posisi yang kamu mau.</div>
        </div>
        <div class="fblock">
          <div class="fblock-title" style="margin-bottom:10px">Background subtitle</div>
          <div class="seg">
            <button :class="{ on: subtitleBg === 'none' }" @click="subtitleBg = 'none'" type="button">Transparan</button>
            <button :class="{ on: subtitleBg === 'semi' }" @click="subtitleBg = 'semi'" type="button">Semi hitam</button>
            <button :class="{ on: subtitleBg === 'full' }" @click="subtitleBg = 'full'" type="button">Kotak penuh</button>
          </div>
        </div>
      </div>
    </div>

    {{-- RIGHT: dedicated phone preview --}}
    <div class="wiz-preview">
      <div class="preview-label">
        <span>Live preview · 9:16</span>
        <span x-text="currentLayout.title"></span>
      </div>

      <div class="phone-frame">
        <div class="phone-notch"></div>
        <div x-html="layoutBg(layout)"></div>

        {{-- channel chip --}}
        <div style="position:absolute;top:52px;left:12px;z-index:5;display:inline-flex;align-items:center;gap:6px;background:rgba(0,0,0,.5);backdrop-filter:blur(6px);padding:4px 10px 4px 4px;border-radius:999px;font-size:9px;font-weight:600;color:#fff">
          <span :style="'width:13px;height:13px;border-radius:50%;background:'+currentLayout.accent"></span>
          <span x-text="meta ? meta.channel : 'Channel Preview'"></span>
        </div>

        {{-- Drag guides: centre line + the strip TikTok's caption/buttons cover --}}
        <div class="pv-guides" x-show="subDragging">
          <div class="pv-guide-mid"></div>
          <div class="pv-guide-ui"><span>tertutup UI TikTok</span></div>
        </div>

        {{-- subtitle ON: karaoke words always visible (ignores hook state); drag to move --}}
        <div x-show="subtitleEnabled" :style="pvSubStyle()" class="pv-sub-drag" :class="{ dragging: subDragging }"
             @pointerdown="startSubDrag($event)" title="Seret untuk memindahkan subtitle">
          <div :style="pvSubBoxStyle()">
            <template x-for="w in pvSubWordsAlways" :key="w.idx">
              <span
                :style="'color:'+(w.active ? subtitleHighlight : subtitleTextColor)+';transition:color .12s;'"
                x-text="w.w + ' '"
              ></span>
            </template>
          </div>
        </div>

        {{-- subtitle OFF: placeholder hint --}}
        <div x-show="!subtitleEnabled" style="position:absolute;inset:0;z-index:6;display:flex;align-items:center;justify-content:center">
          <div style="padding:8px 14px;background:rgba(0,0,0,.55);border-radius:8px;font-size:9px;letter-spacing:.08em;color:rgba(255,255,255,.4);font-family:ui-monospace,monospace;text-transform:uppercase">
            Subtitle dinonaktifkan
          </div>
        </div>

        {{-- progress bar --}}
        <div style="position:absolute;left:14px;right:14px;bottom:16px;z-index:5;height:2px;background:rgba(255,255,255,.22);border-radius:999px;overflow:hidden">
          <div :style="'width:'+pvProgress+'%;height:100%;background:#fff'"></div>
        </div>
      </div>

      {{-- preview meta --}}
      <div style="margin-top:12px;display:flex;flex-direction:column;gap:6px">
        <div style="display:flex;align-items:center;justify-content:space-between;font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--text-dim);letter-spacing:.04em">
          <span>↻ diperbarui langsung</span>
          <span x-text="subtitleFont + ' · ' + subtitleSize + 'px'"></span>
        </div>
        {{-- color legend --}}
        <div x-show="subtitleEnabled" style="display:flex;align-items:center;gap:10px;font-size:11px;color:var(--text-muted)">
          <span style="display:flex;align-items:center;gap:5px">
            <span :style="'display:inline-block;width:10px;height:10px;border-radius:3px;background:'+subtitleTextColor+';border:1px solid rgba(255,255,255,.15)'"></span>
            Teks biasa
          </span>
          <span style="display:flex;align-items:center;gap:5px">
            <span :style="'display:inline-block;width:10px;height:10px;border-radius:3px;background:'+subtitleHighlight"  ></span>
            Kata aktif
          </span>
        </div>
      </div>
    </div>

  </div>
</div>
