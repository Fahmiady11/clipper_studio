{{-- Topbar for pages other than the studio. $active: main-nav section; $guide: guide page key, if any. --}}
<div class="topbar">
  <div class="topbar-left">
    <a class="brand" href="/?new=1" title="Project baru">
      <div class="brand-mark"></div>
      <span class="brand-name">Clipper <span class="brand-sub">Studio</span></span>
    </a>
    @include('partials.main-nav', ['active' => $active ?? null])
  </div>
  <div class="topbar-nav">
    @isset($guide)
      @include('partials.guide', ['page' => $guide])
    @endisset
    @include('partials.user-menu')
  </div>
</div>
