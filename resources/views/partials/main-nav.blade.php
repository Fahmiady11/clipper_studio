{{-- Main sections, identical on every signed-in page. $active: studio | autopilot | music | (none) --}}
@php($active = $active ?? null)
<nav class="main-nav" aria-label="Menu utama">
  <a href="/" @class(['active' => $active === 'studio'])>Studio</a>
  <a href="/autopilot" @class(['active' => $active === 'autopilot'])>Autopilot</a>
  <a href="/music" @class(['active' => $active === 'music'])>Musik</a>
</nav>
