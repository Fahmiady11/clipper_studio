<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>Akun terhubung — Clipper Studio</title>
@include('partials.favicon')
<meta name="csrf-token" content="{{ csrf_token() }}" />
@include('partials.head')
@vite('resources/css/studio.css')
@include('partials.session-guard')
<style>
  .cn-wrap { max-width: 720px; margin: 0 auto; padding: 40px 24px 96px; }
  .cn-wrap h1 { font-size: 28px; font-weight: 700; letter-spacing: -.02em; margin: 16px 0 8px; }
  .cn-lead { color: var(--text-muted); margin: 0 0 28px; line-height: 1.6; }
  .cn-plat { border-top: 1px solid var(--border); padding: 24px 0; }
  .cn-head { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 10px 16px; margin-bottom: 14px; }
  .cn-head h2 { font-size: 17px; margin: 0; }
  .cn-wrap > a.btn-ghost { display: inline-flex; align-items: center; min-height: 44px; text-decoration: none; }
  .cn-head a.btn-secondary, .cn-card a.btn-secondary { display: inline-flex; align-items: center; justify-content: center; min-height: 44px; text-decoration: none; }
  .cn-card { display: flex; align-items: center; gap: 14px; background: var(--surface); border: 1px solid var(--border); border-radius: 14px; padding: 12px 16px; margin-bottom: 8px; }
  .cn-card img, .cn-avatar { width: 44px; height: 44px; border-radius: 50%; object-fit: cover; background: var(--surface-3); flex-shrink: 0; }
  .cn-card b { display: block; font-size: 14.5px; overflow-wrap: anywhere; }
  .cn-card .btn-secondary { min-height: 44px; }
  .cn-note { color: var(--text-muted); font-size: 14px; line-height: 1.6; margin: 0 0 12px; }
  .cn-flash { padding: 10px 14px; border-radius: 10px; background: var(--accent-soft); font-size: 13.5px; margin-bottom: 20px; }
  .cn-flash.err { background: var(--danger-soft); color: var(--danger); }
  @media (max-width: 520px) {
    .cn-wrap { padding: 24px 16px 72px; }
    .cn-card { flex-wrap: wrap; }
    .cn-card form { width: 100%; }
    .cn-card form .btn-secondary { width: 100%; }
  }
</style>
</head>
<body>
<div class="cn-wrap">
  <a href="{{ route('home') }}" class="btn-ghost btn-sm">← Kembali ke Studio</a>
  <h1>Akun terhubung</h1>
  <p class="cn-lead">Tempat clip yang sudah jadi bisa dikirim. Setiap platform meminta izinnya sendiri, dan kamu bisa memutuskan akun kapan saja.</p>

  @if (session('status'))
    <div class="cn-flash" role="status">{{ session('status') }}</div>
  @endif
  @if (session('error'))
    <div class="cn-flash err" role="alert">{{ session('error') }}</div>
  @endif

  @foreach ($platforms as $p)
    <section class="cn-plat" aria-labelledby="cn-{{ $p['key'] }}">
      <div class="cn-head">
        <h2 id="cn-{{ $p['key'] }}">{{ $p['label'] }}</h2>
        @if ($p['configured'])
          <a class="btn-secondary btn-sm" href="{{ $p['connectUrl'] }}">{{ $p['accounts']->isEmpty() ? 'Hubungkan akun' : 'Hubungkan akun lain' }}</a>
        @endif
      </div>

      @unless ($p['configured'])
        <p class="cn-note">Kredensial {{ $p['label'] }} belum diisi admin, jadi akun belum bisa dihubungkan.</p>
      @endunless

      @foreach ($p['accounts'] as $account)
        <div class="cn-card">
          @if ($account->avatarUrl)
            <img src="{{ $account->avatarUrl }}" alt="" />
          @else
            <div class="cn-avatar" aria-hidden="true"></div>
          @endif
          <div style="flex:1;min-width:0"><b>{{ $account->name }}</b></div>
          @if ($p['manageUrl'])
            <a class="btn-secondary btn-sm" href="{{ $p['manageUrl'] }}">Kelola</a>
          @else
            <form method="POST" action="{{ route('connections.disconnect', [$p['key'], $account->id]) }}" onsubmit="return confirm('Putuskan {{ $account->name }}? Clip tidak bisa dikirim ke akun ini sampai kamu menghubungkannya lagi.')">
              @csrf
              @method('DELETE')
              <button type="submit" class="btn-secondary btn-sm">Putuskan</button>
            </form>
          @endif
        </div>
      @endforeach

      @if ($p['configured'] && $p['accounts']->isEmpty())
        <p class="cn-note">Belum ada akun {{ $p['label'] }}. Hubungkan satu untuk mengirim clip ke sana.</p>
      @endif

      @if ($p['key'] === 'instagram')
        <p class="cn-note" style="margin:10px 0 0">Hanya akun Instagram Business atau Creator yang bisa dipakai. Akun pribadi tidak didukung oleh Instagram untuk posting lewat API.</p>
      @endif
    </section>
  @endforeach
</div>
</body>
</html>
