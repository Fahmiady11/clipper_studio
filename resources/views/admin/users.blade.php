<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>Pengguna — Clipper Studio</title>
@include('partials.favicon')
<meta name="csrf-token" content="{{ csrf_token() }}" />
<link rel="preconnect" href="https://fonts.googleapis.com" />
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet" />
@vite('resources/css/studio.css')
@include('partials.session-guard')
<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
<style>
  .us-wrap { max-width: 860px; margin: 0 auto; padding: 40px 24px 96px; }
  .us-head h1 { font-size: 28px; font-weight: 700; letter-spacing: -.02em; margin: 0 0 8px; }
  .us-head p { color: var(--text-muted); margin: 0; font-size: 14px; line-height: 1.6; }
  .us-sec { margin-top: 32px; }
  .us-sec h2 { font-size: 17px; font-weight: 600; margin: 0 0 14px; display: flex; align-items: center; gap: 10px; }
  .us-count { font-family: 'JetBrains Mono', monospace; font-size: 11px; font-weight: 600; color: var(--text-muted); background: var(--surface-2); border: 1px solid var(--border); border-radius: 999px; padding: 2px 9px; }
  .us-count.is-hot { color: var(--yellow); background: var(--yellow-dim); border-color: transparent; }
  .us-list { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); }
  .us-row { display: flex; align-items: center; gap: 14px; padding: 14px 18px; }
  .us-row + .us-row { border-top: 1px solid var(--border); }
  .us-who { flex: 1; min-width: 0; }
  .us-who b { display: block; font-size: 14px; font-weight: 600; }
  .us-who span { display: block; font-size: 12.5px; color: var(--text-muted); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .us-tag { font-family: 'JetBrains Mono', monospace; font-size: 10.5px; font-weight: 600; letter-spacing: .06em; text-transform: uppercase; color: var(--teal); background: var(--teal-dim); padding: 3px 8px; border-radius: 6px; }
  .us-actions { display: flex; gap: 8px; }
  .us-actions form { margin: 0; }
  .us-danger { color: #f87171; }
  .us-empty { padding: 22px 18px; text-align: center; color: var(--text-muted); font-size: 13px; }
  .us-form { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 18px; display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)) auto; gap: 12px; align-items: end; }
  .us-form label { display: flex; flex-direction: column; gap: 6px; font-size: 12.5px; color: var(--text-muted); }
  .us-form .field { width: 100%; }
  .us-err { grid-column: 1 / -1; font-size: 12.5px; color: #f87171; }
  .us-hint { grid-column: 1 / -1; font-size: 12.5px; color: var(--text-dim); }
  @media (max-width: 760px) { .us-form { grid-template-columns: minmax(0, 1fr); } }
  .us-status { margin-top: 20px; padding: 12px 16px; border-radius: var(--radius-sm); background: var(--teal-dim); color: var(--text); font-size: 13.5px; }
  @media (max-width: 560px) {
    .us-row { flex-wrap: wrap; }
    .us-actions { width: 100%; }
    .us-actions form, .us-actions .btn { flex: 1; justify-content: center; }
  }
</style>
</head>
<body>
<div x-data x-cloak>
  @include('partials.topbar', ['active' => null])

  <div class="us-wrap">
    <div class="us-head">
      <h1>Pengguna</h1>
      <p>Buat akun untuk orang lain di sini, atau setujui yang daftar sendiri: akun baru tidak bisa masuk sebelum kamu setujui. Akses yang dicabut langsung berlaku: sesi yang sedang terbuka ikut keluar.</p>
    </div>

    @if (session('status'))
      <div class="us-status" role="status">{{ session('status') }}</div>
    @endif

    <section class="us-sec">
      <h2>Buat akun</h2>
      <form class="us-form" method="POST" action="{{ route('admin.users.store') }}" autocomplete="off">
        @csrf
        <label>Nama<input class="field" name="name" value="{{ old('name') }}" required maxlength="255"></label>
        <label>Email<input class="field" type="email" name="email" value="{{ old('email') }}" required maxlength="255"></label>
        <label>Password awal<input class="field" type="text" name="password" required minlength="8" autocomplete="new-password"></label>
        <button class="btn" type="submit">Buat akun</button>
        @if ($errors->create->any())
          <div class="us-err">{{ $errors->create->first() }}</div>
        @endif
        <div class="us-hint">Akun langsung bisa dipakai. Kirim email dan password awal ke orangnya.</div>
      </form>
    </section>

    <section class="us-sec">
      <h2>Menunggu persetujuan <span class="us-count {{ $pending->isNotEmpty() ? 'is-hot' : '' }}">{{ $pending->count() }}</span></h2>
      <div class="us-list">
        @forelse ($pending as $u)
          <div class="us-row">
            <div class="us-who">
              <b>{{ $u->name }}</b>
              <span>{{ $u->email }} · daftar {{ $u->created_at?->diffForHumans() }}</span>
            </div>
            <div class="us-actions">
              <form method="POST" action="{{ route('admin.users.approve', $u) }}">
                @csrf
                <button class="btn" type="submit">Setujui</button>
              </form>
              <form method="POST" action="{{ route('admin.users.reject', $u) }}" onsubmit="return confirm(@js('Tolak dan hapus pendaftaran '.$u->email.'?'))">
                @csrf
                @method('DELETE')
                <button class="btn btn-ghost us-danger" type="submit">Tolak</button>
              </form>
            </div>
          </div>
        @empty
          <div class="us-empty">Tidak ada pendaftaran baru.</div>
        @endforelse
      </div>
    </section>

    <section class="us-sec">
      <h2>Punya akses <span class="us-count">{{ $approved->count() }}</span></h2>
      <div class="us-list">
        @foreach ($approved as $u)
          <div class="us-row">
            <div class="us-who">
              <b>{{ $u->name }}</b>
              <span>{{ $u->email }}</span>
            </div>
            @if ($u->is_admin)
              <span class="us-tag">Admin</span>
            @else
              <div class="us-actions">
                <form method="POST" action="{{ route('admin.users.revoke', $u) }}" onsubmit="return confirm(@js('Cabut akses '.$u->email.'? Datanya tetap tersimpan.'))">
                  @csrf
                  <button class="btn btn-ghost us-danger" type="submit">Cabut akses</button>
                </form>
              </div>
            @endif
          </div>
        @endforeach
      </div>
    </section>
  </div>
</div>
</body>
</html>
