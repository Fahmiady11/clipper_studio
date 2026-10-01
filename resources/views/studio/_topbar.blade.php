<div class="topbar">
  <div class="brand">
    <div class="brand-mark"></div>
    <span class="brand-name">Clipper <span class="brand-sub">Studio</span></span>
  </div>
  <div class="topbar-nav">
    <a class="active" href="#" @click.prevent="restart()"><span class="lbl-long">Project baru</span><span class="lbl-short">Baru</span></a>
    <a href="/autopilot">Autopilot</a>
    <a href="#" @click.prevent="historyOpen = true; loadHistory()" style="position:relative">
      Riwayat
      <span x-show="history.length > 0" x-text="history.length"
        style="position:absolute;top:-4px;right:-6px;background:var(--teal);color:#042f2c;border-radius:999px;font-size:9px;font-weight:700;padding:1px 5px;font-family:'JetBrains Mono',monospace;line-height:1.4">
      </span>
    </a>
    @include('partials.user-menu')
  </div>
</div>
