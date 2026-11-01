<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>Paket &amp; kuota — Clipper Studio</title>
@include('partials.favicon')
<meta name="csrf-token" content="{{ csrf_token() }}" />
@include('partials.head')
@vite('resources/css/studio.css')
@include('partials.session-guard')
<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
<style>
  .pr-wrap { max-width: 860px; margin: 0 auto; padding: 40px 24px 96px; }
  .pr-wrap h1 { font-size: 28px; font-weight: 700; letter-spacing: -.02em; margin: 0 0 8px; }
  .pr-lead { color: var(--text-muted); font-size: 14px; line-height: 1.6; margin: 0 0 28px; }
  .pr-usage { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 18px 20px; margin-bottom: 28px; }
  .pr-usage-top { display: flex; justify-content: space-between; align-items: baseline; gap: 12px; flex-wrap: wrap; font-size: 14px; }
  .pr-num { font-family: var(--font-mono); font-weight: 600; }
  .pr-bar { height: 8px; border-radius: 999px; background: var(--surface-3); margin: 12px 0 8px; overflow: hidden; }
  .pr-bar > i { display: block; height: 100%; background: var(--accent); }
  .pr-bar.is-full > i { background: var(--danger); }
  .pr-note { font-size: 12.5px; color: var(--text-dim); margin: 0; }
  .pr-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; }
  .pr-plan { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 22px; display: flex; flex-direction: column; gap: 14px; }
  .pr-plan.is-current { border-color: var(--accent-ink); }
  .pr-plan h2 { font-size: 17px; margin: 0; display: flex; align-items: center; gap: 10px; }
  .pr-price { font-family: var(--font-mono); font-size: 32px; font-weight: 600; letter-spacing: -.02em; }
  .pr-price small { font-size: 13px; color: var(--text-dim); font-weight: 400; }
  .pr-minutes { font-size: 14px; color: var(--text-muted); margin: 0; }
  .pr-minutes b { color: var(--text); font-family: var(--font-mono); }
  .pr-tag { font-family: var(--font-mono); font-size: 10.5px; font-weight: 600; letter-spacing: .06em; text-transform: uppercase; color: var(--accent-ink); background: var(--accent-soft); padding: 3px 8px; border-radius: 6px; }
  .pr-cta { margin-top: auto; }
  .pr-foot { margin-top: 24px; font-size: 13px; color: var(--text-dim); line-height: 1.6; }
  @media (max-width: 640px) { .pr-grid { grid-template-columns: minmax(0, 1fr); } }
</style>
</head>
<body>
<div x-data x-cloak>
  @include('partials.topbar', ['active' => null])

  @php
    $limit = $current->render_minutes;
    $pct = $limit > 0 ? min(100, round($usedMinutes / $limit * 100)) : 100;
    $email = config('services.clipper.contact_email');
  @endphp
  <div class="pr-wrap">
    <h1>Paket &amp; kuota</h1>
    <p class="pr-lead">Kuota dihitung dari menit video hasil render, bukan dari video sumber. Menganalisis video untuk mencari momen tidak memakai kuota.</p>

    <div class="pr-usage">
      <div class="pr-usage-top">
        <span>Paket {{ $current->name }}</span>
        <span><span class="pr-num">{{ $usedMinutes }}</span> dari <span class="pr-num">{{ $limit }}</span> menit terpakai bulan ini</span>
      </div>
      <div class="pr-bar {{ $pct >= 100 ? 'is-full' : '' }}" role="progressbar" aria-valuemin="0" aria-valuemax="{{ $limit }}" aria-valuenow="{{ min($usedMinutes, $limit) }}" aria-label="Kuota render bulan ini"><i style="width: {{ $pct }}%"></i></div>
      <p class="pr-note">Reset {{ $resetsOn->translatedFormat('j F Y') }}. Render yang gagal atau dibatalkan tidak memotong kuota.</p>
    </div>

    <div class="pr-grid">
      @foreach ($plans as $plan)
        <section class="pr-plan {{ $plan->id === $current->id ? 'is-current' : '' }}">
          <h2>{{ $plan->name }} @if ($plan->id === $current->id)<span class="pr-tag">Paketmu</span>@endif</h2>
          <div class="pr-price">${{ $plan->priceUsd() }}<small> / bulan</small></div>
          <p class="pr-minutes"><b>{{ $plan->render_minutes }}</b> menit render per bulan</p>
          <div class="pr-cta">
            @if ($plan->id === $current->id)
              <span class="btn-secondary btn-sm" aria-disabled="true">Paket aktif</span>
            @elseif ($plan->price_usd_cents > $current->price_usd_cents)
              @if ($email)
                <a class="btn" href="mailto:{{ $email }}?subject={{ rawurlencode('Upgrade ke paket '.$plan->name) }}">Minta upgrade ke {{ $plan->name }}</a>
              @else
                <span class="pr-note">Minta admin memindahkan akunmu ke paket {{ $plan->name }}.</span>
              @endif
            @endif
          </div>
        </section>
      @endforeach
    </div>

    <p class="pr-foot">Pembayaran belum otomatis. Setelah kamu minta upgrade, admin mengaktifkan paketnya di akunmu.</p>
  </div>
</div>
</body>
</html>
