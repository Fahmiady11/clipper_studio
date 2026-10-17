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
    @if (Auth::user()->is_admin)
      @php($waiting = \App\Models\User::whereNull('approved_at')->count())
      <a class="user-menu-item" href="{{ route('admin.users') }}">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg>
        Pengguna
        @if ($waiting > 0)
          <span style="margin-left:auto;font-family:var(--font-mono);font-size:10.5px;font-weight:600;color:var(--warn);background:var(--warn-soft);padding:1px 7px;border-radius:999px">{{ $waiting }}</span>
        @endif
      </a>
    @endif
    <button type="button" class="user-menu-item user-menu-theme" onclick="toggleTheme()">
      <svg class="ic-moon" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 12.8A9 9 0 1111.2 3a7 7 0 009.8 9.8z"/></svg>
      <svg class="ic-sun" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>
      Ganti tema
    </button>
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
