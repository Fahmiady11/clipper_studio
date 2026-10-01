{{-- Account menu for the topbar. Logging out is a deliberate second click, not the name itself. --}}
<div class="user-menu" x-data="{ open: false }" @click.outside="open = false" @keydown.escape.window="open = false">
  <button type="button" class="user-menu-btn" @click="open = !open" :aria-expanded="open" aria-haspopup="true">
    <span class="nav-avatar">{{ Auth::user()->initials() }}</span>
    <span class="user-menu-name">{{ Auth::user()->name }}</span>
    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>
  </button>
  <div class="user-menu-pop" x-show="open" x-transition.origin.top.right x-cloak>
    <div class="user-menu-head">
      <b>{{ Auth::user()->name }}</b>
      <span>{{ Auth::user()->email }}</span>
    </div>
    <a class="user-menu-item" href="/music">
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/></svg>
      Library musik
    </a>
    {{-- Drop the studio draft so the next person on this browser starts clean --}}
    <form method="POST" action="{{ route('logout') }}" @submit="try { localStorage.removeItem('clipper_studio_v1') } catch (e) {}">
      @csrf
      <button type="submit" class="user-menu-item">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4M16 17l5-5-5-5M21 12H9"/></svg>
        Keluar
      </button>
    </form>
  </div>
</div>
