<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>Channel YouTube — Clipper Studio</title>
@include('partials.favicon')
<meta name="csrf-token" content="{{ csrf_token() }}" />
@include('partials.head')
@vite('resources/css/studio.css')
@include('partials.session-guard')
<style>
  .yt-wrap { max-width: 720px; margin: 0 auto; padding: 40px 24px 96px; }
  .yt-wrap h1 { font-size: 28px; font-weight: 700; letter-spacing: -.02em; margin: 0 0 8px; }
  .yt-lead { color: var(--text-dim); margin: 0 0 28px; }
  .yt-why { background: var(--surface); border: 1px solid var(--border); border-radius: 14px; padding: 18px 20px; margin-bottom: 24px; }
  .yt-why h2 { font-size: 15px; margin: 0 0 8px; }
  .yt-why ol { margin: 0; padding-left: 18px; color: var(--text-muted); font-size: 14px; line-height: 1.7; }
  .yt-card { display: flex; align-items: center; gap: 14px; background: var(--surface); border: 1px solid var(--border); border-radius: 14px; padding: 14px 16px; margin-bottom: 10px; }
  .yt-card img { width: 44px; height: 44px; border-radius: 50%; object-fit: cover; background: var(--surface-3); }
  .yt-card b { display: block; font-size: 14.5px; }
  .yt-card span { font-size: 12px; color: var(--text-dim); font-family: var(--font-mono); }
  .yt-empty { color: var(--text-dim); font-size: 14px; margin-bottom: 16px; }
  .yt-actions { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; margin-top: 24px; }
  .yt-warn { padding: 10px 14px; border-radius: 10px; background: var(--accent-soft); font-size: 13.5px; margin-bottom: 20px; }
</style>
</head>
<body>
<div class="yt-wrap">
  <a href="{{ route('home') }}" class="btn-ghost btn-sm">← Kembali ke Studio</a>
  <h1 style="margin-top:16px">Channel YouTube</h1>
  <p class="yt-lead">Hubungkan channel YouTube milikmu supaya Clipper boleh mengolah videonya.</p>

  <div class="yt-why">
    <h2>Kenapa harus verifikasi channel?</h2>
    <ol>
      <li>Clipper hanya memproses <b>video milikmu sendiri</b>, tidak pernah video orang lain.</li>
      <li>Cara memastikannya: kamu login Google dan memberi izin baca channel (scope <code>youtube.readonly</code>). Clipper menyimpan ID channel terverifikasi itu.</li>
      <li>Link video dari channel lain akan ditolak dengan pesan yang jelas.</li>
      <li>Autopilot hanya bisa memantau channel yang sudah terverifikasi ini.</li>
      <li>Kalau videomu belum ada di YouTube, <b>unggah file videonya langsung</b> di Studio — tidak perlu verifikasi.</li>
    </ol>
  </div>

  @if (! $configured)
    <div class="yt-warn">Kredensial Google (OAuth) belum diisi admin, jadi tombol hubungkan belum aktif.</div>
  @endif
  @if (session('status'))
    <div class="yt-warn">{{ session('status') }}</div>
  @endif
  @if (session('error'))
    <div class="yt-warn" style="background:var(--danger-soft);color:var(--danger)">{{ session('error') }}</div>
  @endif

  @foreach ($accounts as $account)
    <div class="yt-card">
      @if ($account->thumbnail_url)
        <img src="{{ $account->thumbnail_url }}" alt="" />
      @else
        <div style="width:44px;height:44px;border-radius:50%;background:var(--surface-3)"></div>
      @endif
      <div style="flex:1;min-width:0">
        <b>{{ $account->title ?: 'Channel #'.$account->id }}</b>
        <span>{{ $account->channel_id }}</span>
      </div>
      <form method="POST" action="{{ route('youtube.settings.disconnect', $account) }}" onsubmit="return confirm('Putuskan channel ini? Link video dari channel ini akan ditolak sampai kamu hubungkan lagi.')">
        @csrf
        @method('DELETE')
        <button type="submit" class="btn-secondary btn-sm">Putuskan</button>
      </form>
    </div>
  @endforeach

  @if ($accounts->isEmpty())
    <p class="yt-empty">Belum ada channel terverifikasi.</p>
  @endif

  <div class="yt-actions">
    @if ($configured)
      <a class="btn" href="/youtube/connect">{{ $accounts->isEmpty() ? 'Hubungkan channel YouTube' : 'Hubungkan channel lain' }}</a>
    @endif
    <a class="btn-secondary btn-sm" href="{{ route('home') }}">Upload file video langsung</a>
  </div>
</div>
</body>
</html>
