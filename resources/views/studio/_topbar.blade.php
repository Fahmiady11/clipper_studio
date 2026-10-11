<div class="topbar">
  <div class="topbar-left">
    <a class="brand" href="/" @click.prevent="restart()" title="Project baru">
      <div class="brand-mark"></div>
      <span class="brand-name">Clipper <span class="brand-sub">Studio</span></span>
    </a>
    @include('partials.main-nav', ['active' => 'studio'])
  </div>
  <div class="topbar-nav">
    <a href="#" @click.prevent="historyOpen = true; loadHistory()" class="topbar-icon-link" aria-label="Riwayat">
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
      <span class="lbl-long">Riwayat</span>
      <span class="topbar-badge" x-show="history.length > 0" x-text="history.length"></span>
    </a>
    @include('partials.guide', ['page' => 'studio'])
    <a href="#" class="topbar-cta" @click.prevent="restart()" aria-label="Project baru">
      <span aria-hidden="true">+</span><span class="lbl-long">Project baru</span>
    </a>
    @include('partials.user-menu')
  </div>
</div>
