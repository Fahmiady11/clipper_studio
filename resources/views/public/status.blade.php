@extends('public._layout')
@section('title', 'Status')

@section('style')
.st-now { display: flex; align-items: center; gap: 14px; background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 22px 24px; margin: 24px 0 16px; }
.st-dot { width: 12px; height: 12px; border-radius: 50%; flex: none; background: var(--accent-ink); }
.st-now.is-busy .st-dot { background: var(--warn); }
.st-now.is-overloaded .st-dot { background: var(--danger); }
.st-now h2 { font-size: 20px; margin: 0 0 2px; }
.st-now p { margin: 0; font-size: 14px; }
.st-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
.st-cell { border: 1px solid var(--border); border-radius: var(--radius); padding: 16px 18px; }
.st-num { font-family: var(--font-mono); font-size: 28px; font-weight: 600; display: block; }
.st-label { font-size: 13px; color: var(--text-muted); }
.st-when { font-size: 13px; color: var(--text-dim); margin-top: 16px; }
@endsection

@section('content')
<article class="pb-doc">
  <h1>Status</h1>
  <p class="pb-updated">Clip rendering capacity during the closed beta.</p>

  <div class="st-now is-{{ $level }}" role="status">
    <span class="st-dot" aria-hidden="true"></span>
    <div>
      <h2>{{ $headline }}</h2>
      <p>{{ $detail }}</p>
    </div>
  </div>

  <div class="st-grid">
    <div class="st-cell"><span class="st-num">{{ $running }}</span><span class="st-label">jobs running</span></div>
    <div class="st-cell"><span class="st-num">{{ $queued }}</span><span class="st-label">jobs waiting</span></div>
  </div>

  <p class="st-when">Checked {{ $sampledAt->diffForHumans() }}. This page does not refresh itself.</p>
</article>
@endsection
