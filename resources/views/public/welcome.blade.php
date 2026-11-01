@extends('public._layout')
@section('title', 'Turn long videos into clips for TikTok, Shorts and Reels')
@section('description', 'Clipper Studio picks the best moments from a long YouTube video, edits them into vertical 9:16 clips with subtitles and a hook, and sends the clips you approve to your own TikTok, YouTube, Facebook, Instagram and Threads accounts.')
@section('main_class', 'wide')

@section('style')
/* ── Hero ─────────────────────────────────────────────────────────────── */
.wl-hero { position: relative; display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1.05fr); gap: 48px; align-items: center; padding: 16px 0 56px; }
.wl-hero h1 { font-size: 54px; font-weight: 800; line-height: 1.02; letter-spacing: -.045em; margin: 0 0 22px; text-wrap: balance; }
/* The highlighted word, as a karaoke subtitle would show it */
.wl-hero h1 mark { color: var(--on-accent); background: var(--accent); border-radius: 10px; padding: 0 .14em; -webkit-box-decoration-break: clone; box-decoration-break: clone; }
.wl-lead { font-size: 17px; line-height: 1.65; color: var(--text-muted); margin: 0 0 28px; max-width: 520px; }
.wl-cta { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
.wl-cta .pb-btn { min-height: 48px; padding: 12px 22px; font-size: 15px; }
.wl-cta small { color: var(--text-dim); font-size: 13px; }

/* ── Animated scene: long video → AI picks → vertical clip → TikTok draft ── */
.wl-scene { position: relative; height: 470px; }
.wl-src { position: absolute; left: 0; top: 60px; width: 64%; background: var(--surface); border: 1px solid var(--border-strong); border-radius: 16px; padding: 12px; box-shadow: 0 30px 80px var(--phone-shadow); animation: wl-float 7s ease-in-out infinite; }
.wl-src-frame { position: relative; aspect-ratio: 16 / 9; border-radius: 10px; overflow: hidden; background: linear-gradient(160deg, #1d3b4a, #141a2e 60%, #241a33); }
.wl-person { position: absolute; bottom: 0; width: 26%; aspect-ratio: 3 / 4; border-radius: 50% 50% 12px 12px / 38% 38% 12px 12px; background: linear-gradient(180deg, #3b5768, #23384a); }
.wl-person::before { content: ''; position: absolute; left: 50%; top: -34%; width: 58%; aspect-ratio: 1; transform: translateX(-50%); border-radius: 50%; background: #4b6a7c; }
.wl-person.p1 { left: 16%; height: auto; }
.wl-person.p2 { right: 16%; background: linear-gradient(180deg, #4a3d63, #2c2340); }
.wl-person.p2::before { background: #5d4d7a; }
.wl-mic { position: absolute; bottom: 0; width: 3px; height: 34%; background: #6b7280; }
.wl-mic::before { content: ''; position: absolute; top: -10px; left: -5px; width: 13px; height: 18px; border-radius: 6px; background: #9ca3af; }
.wl-mic.m1 { left: 36%; } .wl-mic.m2 { right: 36%; }
.wl-live { position: absolute; top: 10px; left: 10px; font-family: var(--font-mono); font-size: 10px; font-weight: 600; color: #fff; background: rgba(0, 0, 0, .45); border-radius: 5px; padding: 3px 7px; }
.wl-time { position: absolute; bottom: 10px; right: 10px; font-family: var(--font-mono); font-size: 10px; color: #fff; background: rgba(0, 0, 0, .45); border-radius: 5px; padding: 3px 7px; }
.wl-track { position: relative; margin-top: 12px; height: 46px; border-radius: 8px; background: var(--surface-2); overflow: hidden; display: flex; align-items: center; gap: 2px; padding: 0 8px; }
.wl-track i { flex: 1; border-radius: 2px; background: var(--border-strong); }
.wl-pick { position: absolute; top: 4px; bottom: 4px; border-radius: 6px; border: 1.5px solid var(--accent-ink); background: var(--accent-soft); opacity: 0; animation: wl-pick 6s ease-in-out infinite; }
.wl-pick.k1 { left: 12%; width: 13%; }
.wl-pick.k2 { left: 44%; width: 16%; animation-delay: 1.2s; }
.wl-pick.k3 { left: 73%; width: 12%; animation-delay: 2.4s; }
.wl-head { position: absolute; top: 0; bottom: 0; width: 2px; background: #fff; box-shadow: 0 0 10px rgba(255, 255, 255, .7); animation: wl-scan 6s linear infinite; }
.wl-src-label { display: flex; justify-content: space-between; margin-top: 10px; font-size: 12px; color: var(--text-muted); }
.wl-src-label b { color: var(--accent-ink); font-weight: 600; }

.wl-phone { position: absolute; right: 0; top: 0; width: 220px; aspect-ratio: 9 / 18.5; border-radius: 30px; padding: 8px; background: #0b0b0f; border: 1px solid var(--border-strong); box-shadow: 0 40px 90px var(--phone-shadow); animation: wl-float 7s ease-in-out -3.5s infinite; }
.wl-screen { position: relative; height: 100%; border-radius: 23px; overflow: hidden; background: linear-gradient(170deg, #1d3b4a, #10202c 55%, #241a33); }
.wl-screen .wl-person.p1 { left: 50%; width: 62%; transform: translateX(-50%); }
.wl-notch { position: absolute; top: 7px; left: 50%; transform: translateX(-50%); width: 64px; height: 18px; border-radius: 10px; background: #000; z-index: 3; }
.wl-hook { position: absolute; top: 24%; left: 0; right: 0; display: flex; flex-direction: column; align-items: center; gap: 3px; animation: wl-hook 6s ease-in-out infinite; transform-origin: center; }
.wl-hook span { font-weight: 800; font-size: 15px; line-height: 1.1; padding: 3px 8px; border-radius: 5px; background: #fff; color: #0a0a0c; }
.wl-hook span:last-child { background: #D4FF3A; }
.wl-sub { position: absolute; bottom: 18%; left: 10px; right: 10px; text-align: center; }
.wl-sub-box { display: inline-block; padding: 5px 9px; border-radius: 9px; background: rgba(0, 0, 0, .6); font-weight: 800; font-size: 13px; line-height: 1.3; color: #fff; }
.wl-sub-box .on { color: #D4FF3A; }
.wl-face { position: absolute; left: 25%; top: 43%; width: 50%; height: 25%; animation: wl-face 6s ease-in-out infinite; }
.wl-face i { position: absolute; width: 14px; height: 14px; border: 2px solid #D4FF3A; }
.wl-face i:nth-child(1) { top: 0; left: 0; border-width: 2px 0 0 2px; border-radius: 4px 0 0 0; }
.wl-face i:nth-child(2) { top: 0; right: 0; border-width: 2px 2px 0 0; border-radius: 0 4px 0 0; }
.wl-face i:nth-child(3) { bottom: 0; left: 0; border-width: 0 0 2px 2px; border-radius: 0 0 0 4px; }
.wl-face i:nth-child(4) { bottom: 0; right: 0; border-width: 0 2px 2px 0; border-radius: 0 0 4px 0; }
.wl-face span { position: absolute; top: -15px; left: 0; font-family: var(--font-mono); font-size: 8px; font-weight: 600; letter-spacing: .08em; color: #D4FF3A; }
.wl-rail { position: absolute; right: 8px; bottom: 46%; display: flex; flex-direction: column; gap: 12px; }
.wl-rail i { width: 26px; height: 26px; border-radius: 50%; background: rgba(255, 255, 255, .18); }
.wl-bar { position: absolute; left: 0; right: 0; bottom: 0; height: 3px; background: rgba(255, 255, 255, .15); }
.wl-bar::after { content: ''; position: absolute; inset: 0 auto 0 0; width: 100%; background: #fff; transform-origin: left; animation: wl-progress 6s linear infinite; }

.wl-toast { position: absolute; right: -6px; bottom: 26px; display: flex; align-items: center; gap: 10px; background: var(--surface); border: 1px solid var(--border-strong); border-radius: 12px; padding: 10px 14px; box-shadow: 0 20px 50px var(--phone-shadow); font-size: 13px; animation: wl-toast 6s ease-in-out infinite; }
.wl-toast-ic { width: 28px; height: 28px; border-radius: 8px; display: grid; place-items: center; background: var(--accent-soft); color: var(--accent-ink); }
.wl-toast b { display: block; font-size: 13px; }
.wl-toast span { color: var(--text-muted); font-size: 12px; }

/* ── Features & steps ─────────────────────────────────────────────────── */
.wl-sec { margin-top: 72px; }
.wl-sec-head { margin-bottom: 28px; max-width: 620px; }
.wl-sec-head h2 { font-size: 36px; font-weight: 800; letter-spacing: -.035em; margin: 0 0 8px; }
.wl-sec-head p { color: var(--text-muted); margin: 0; font-size: 16px; }
.wl-lead-feats { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; margin-bottom: 40px; }
.wl-lead-feat { background: var(--surface); border: 1px solid var(--border); border-radius: 20px; padding: 28px; }
.wl-lead-feats.one { grid-template-columns: minmax(0, 1fr); }
.wl-lead-feat.wide p { max-width: 78ch; }
.wl-lead-feat h3 { font-size: 24px; font-weight: 700; letter-spacing: -.025em; margin: 18px 0 8px; }
.wl-lead-feat p { font-size: 15px; line-height: 1.6; color: var(--text-muted); margin: 0; max-width: 46ch; }
.wl-feats { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 28px 48px; margin: 0; padding: 0; list-style: none; }
.wl-feat { display: grid; grid-template-columns: 38px minmax(0, 1fr); gap: 0 16px; }
.wl-feat-ic { width: 38px; height: 38px; border-radius: 10px; display: grid; place-items: center; background: var(--accent-soft); color: var(--accent-ink); }
.wl-lead-feat .wl-feat-ic { width: 46px; height: 46px; border-radius: 12px; }
.wl-feat h3 { font-size: 16px; margin: 7px 0 4px; }
.wl-feat p { grid-column: 2; font-size: 14.5px; line-height: 1.6; color: var(--text-muted); margin: 0; }
.wl-steps { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 14px; counter-reset: st; }
.wl-step { position: relative; border-top: 2px solid var(--text); padding: 18px 0 0; }
.wl-step::before { counter-increment: st; content: counter(st, decimal-leading-zero); font-family: var(--font-mono); font-size: 15px; font-weight: 600; color: var(--accent-ink); }
.wl-step h3 { font-size: 20px; letter-spacing: -.02em; margin: 10px 0 6px; }
.wl-step p { font-size: 14px; line-height: 1.6; color: var(--text-muted); margin: 0; }
.wl-box { background: var(--surface); border: 1px solid var(--border); border-radius: 16px; padding: 26px; display: grid; grid-template-columns: auto minmax(0, 1fr); gap: 20px; }
.wl-box-ic { width: 46px; height: 46px; border-radius: 12px; display: grid; place-items: center; color: var(--accent-ink); background: var(--surface-2); border: 1px solid var(--border-strong); }
.wl-box h2 { font-size: 19px; margin: 0 0 10px; }
.wl-box > div > p { font-size: 14.5px; line-height: 1.7; color: var(--text-muted); margin: 0 0 4px; }
.wl-box h3 { font-size: 15px; margin: 20px 0 6px; }
.wl-box ul + p { margin-top: 20px; }
.wl-box code { font-family: var(--font-mono); font-size: 12.5px; color: var(--text); overflow-wrap: anywhere; }
.wl-box ul { margin: 0; padding-left: 18px; }
.wl-box li { font-size: 14.5px; line-height: 1.7; color: var(--text-muted); }
.wl-box li strong { color: var(--text); }
.wl-band { margin-top: 72px; text-align: center; padding: 44px 24px; border-radius: 24px; background: var(--accent); color: var(--on-accent); }
.wl-band h2 { font-size: 40px; font-weight: 800; letter-spacing: -.04em; margin: 0 0 8px; }
.wl-band p { margin: 0 0 22px; opacity: .8; }
.wl-band-cta { display: flex; gap: 10px; flex-wrap: wrap; justify-content: center; }
.wl-band .pb-btn { min-height: 48px; padding: 12px 22px; font-size: 15px; color: var(--accent); background: var(--on-accent); }
.wl-band .pb-btn.ghost { color: var(--on-accent); background: transparent; border: 1px solid var(--on-accent); }

/* Reveal on scroll (class added by the script below; without JS everything simply shows) */
.js .wl-reveal { opacity: 0; transform: translateY(18px); transition: opacity .6s ease, transform .6s ease; }
.js .wl-reveal.in { opacity: 1; transform: none; }

@keyframes wl-float { 50% { transform: translateY(-10px); } }
@keyframes wl-scan { from { left: 0; } to { left: 100%; } }
@keyframes wl-pick { 0%, 15% { opacity: 0; transform: scaleY(.6); } 25%, 80% { opacity: 1; transform: none; } 100% { opacity: 0; } }
@keyframes wl-hook { 0% { opacity: 0; transform: scale(.82); } 6% { opacity: 1; transform: scale(1.08); } 10%, 42% { opacity: 1; transform: scale(1); } 50%, 100% { opacity: 0; transform: scale(1); } }
@keyframes wl-face { 0%, 100% { transform: translate(0, 0); } 30% { transform: translate(-4%, 2%); } 65% { transform: translate(5%, -1%); } }
@keyframes wl-progress { from { transform: scaleX(0); } to { transform: scaleX(1); } }
@keyframes wl-toast { 0%, 55% { opacity: 0; transform: translateY(12px); } 62%, 92% { opacity: 1; transform: none; } 100% { opacity: 0; } }

@media (prefers-reduced-motion: reduce) {
  .wl-src, .wl-phone, .wl-head, .wl-pick, .wl-hook, .wl-bar::after, .wl-toast, .wl-face { animation: none !important; }
  .wl-pick, .wl-toast { opacity: 1; }
  .js .wl-reveal { opacity: 1; transform: none; transition: none; }
}

@media (max-width: 920px) {
  .wl-hero { grid-template-columns: minmax(0, 1fr); gap: 32px; text-align: center; }
  .wl-lead { margin-left: auto; margin-right: auto; }
  .wl-cta { justify-content: center; }
  .wl-scene { max-width: 520px; width: 100%; margin: 0 auto; }
}
@media (max-width: 600px) {
  .wl-hero h1 { font-size: 42px; }
  .wl-lead-feats, .wl-feats { grid-template-columns: minmax(0, 1fr); }
  .wl-band h2 { font-size: 30px; }
  .wl-scene { height: 400px; }
  .wl-src { width: 70%; top: 70px; }
  .wl-phone { width: 168px; }
  .wl-hook span { font-size: 12px; }
  .wl-sub-box { font-size: 11px; }
  .wl-toast { right: 0; bottom: 6px; }
  .wl-steps { grid-template-columns: minmax(0, 1fr); gap: 24px; }
  .wl-box { grid-template-columns: minmax(0, 1fr); }
  .wl-sec-head h2 { font-size: 28px; }
}
@endsection

@section('content')
  <section class="wl-hero">
    <div>
      <h1>Turn long videos into <mark>short clips</mark> for TikTok, Shorts and Reels</h1>
      <p class="wl-lead">Clipper Studio picks the best moments from a long YouTube video and edits them into vertical 9:16 clips: pauses cut out, faces followed, karaoke subtitles, an opening hook and B-roll. The clips you approve are sent or scheduled to your own TikTok, YouTube, Facebook, Instagram and Threads accounts.</p>
      <div class="wl-cta">
        @auth
          <a class="pb-btn" href="{{ route('home') }}">Open app</a>
        @else
          <a class="pb-btn" href="{{ route('login') }}">Sign in to Studio</a>
          <small>Closed beta, sign up with an invitation code</small>
        @endauth
      </div>
    </div>

    {{-- Illustration of the flow; decorative, so hidden from screen readers --}}
    <div class="wl-scene" aria-hidden="true">
      <div class="wl-src">
        <div class="wl-src-frame">
          <div class="wl-person p1"></div><div class="wl-person p2"></div>
          <div class="wl-mic m1"></div><div class="wl-mic m2"></div>
          <span class="wl-live">● PODCAST</span>
          <span class="wl-time">58:12</span>
        </div>
        <div class="wl-track" id="wl-track">
          <span class="wl-pick k1"></span><span class="wl-pick k2"></span><span class="wl-pick k3"></span>
          <span class="wl-head"></span>
        </div>
        <div class="wl-src-label"><span>AI is finding the best moments…</span><b>3 clips</b></div>
      </div>

      <div class="wl-phone">
        <div class="wl-screen">
          <div class="wl-notch"></div>
          <div class="wl-person p1"></div>
          <div class="wl-face"><i></i><i></i><i></i><i></i><span>FACE</span></div>
          <div class="wl-hook"><span>The habit that</span><span>changed everything</span></div>
          <div class="wl-rail"><i></i><i></i><i></i></div>
          <div class="wl-sub"><span class="wl-sub-box" id="wl-sub">Start small every single day</span></div>
          <div class="wl-bar"></div>
        </div>
      </div>

      <div class="wl-toast" id="wl-toast">
        <span class="wl-toast-ic"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg></span>
        <div><b>Draft sent to TikTok</b><span>Ready to edit in your inbox</span></div>
      </div>
    </div>
  </section>

  <section class="wl-sec">
    <div class="wl-sec-head wl-reveal">
      <h2>From one long video to clips ready to post</h2>
      <p>Paste a YouTube link, pick the clips you like, then render.</p>
    </div>
    <div class="wl-lead-feats">
      <div class="wl-lead-feat wl-reveal">
        <div class="wl-feat-ic" aria-hidden="true"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12v0M6 9v6M9 6v12M15 8v8M18 10v4M21 12v0"/><rect x="11" y="3" width="2" height="18" rx="1"/></svg></div>
        <h3>AI picks the moments</h3><p>The transcript is read to find the parts most likely to keep viewers watching. Each clip gets a viral score with the reasons behind it, and starts and ends on a full sentence.</p>
      </div>
      <div class="wl-lead-feat wl-reveal">
        <div class="wl-feat-ic" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7V5a2 2 0 012-2h2M17 3h2a2 2 0 012 2v2M21 17v2a2 2 0 01-2 2h-2M7 21H5a2 2 0 01-2-2v-2"/><circle cx="12" cy="10" r="3"/><path d="M7.5 17a5 5 0 019 0"/></svg></div>
        <h3>Face tracking</h3><p>The 9:16 frame follows faces and cuts to whoever is talking, so the speaker never drifts out of shot. Or keep the full shot over a blurred background.</p>
      </div>
    </div>
    <ul class="wl-feats">
      <li class="wl-feat wl-reveal">
        <div class="wl-feat-ic" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="6" y="2" width="12" height="20" rx="2"/><path d="M6 12h12"/></svg></div>
        <h3>Split-screen gameplay layout</h3><p>The speaker on top, gameplay footage you upload yourself looping below, subtitles at the seam. Available once you have uploaded footage in Settings.</p>
      </li>
      <li class="wl-feat wl-reveal">
        <div class="wl-feat-ic" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 4v16M18 4v16"/><path d="M10 9l4 3-4 3"/></svg></div>
        <h3>Jump cuts and zoom</h3><p>Long pauses and filler words like "um" are cut out, and the picture punches in at cuts and sentence ends, as an editor would.</p>
      </li>
      <li class="wl-feat wl-reveal">
        <div class="wl-feat-ic" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M7 15h4M13 15h4M7 11h10"/></svg></div>
        <h3>Karaoke subtitles</h3><p>The word being spoken lights up. Pick a ready-made style or set your own font, colour and position, with emoji on key words.</p>
      </li>
      <li class="wl-feat wl-reveal">
        <div class="wl-feat-ic" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7V4h16v3M9 20h6M12 4v16"/></svg></div>
        <h3>Hook, title and progress bar</h3><p>A headline pops in for the first seconds, the topic can stay on top, and a thin bar shows how much is left.</p>
      </li>
      <li class="wl-feat wl-reveal">
        <div class="wl-feat-ic" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="6" width="14" height="12" rx="2"/><path d="M16 10l6-3v10l-6-3"/></svg></div>
        <h3>Automatic B-roll</h3><p>Stock clips from Pexels or Pixabay that match what is being said are cut in over the speaker, under the subtitles.</p>
      </li>
      <li class="wl-feat wl-reveal">
        <div class="wl-feat-ic" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/></svg></div>
        <h3>Music, sound effects and loudness</h3><p>Background music from a library you fill yourself, picked by mood. Sound effects (whoosh, pop, ding) can be switched on per project. Without music, the voice is levelled to platform loudness.</p>
      </li>
      <li class="wl-feat wl-reveal">
        <div class="wl-feat-ic" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="3"/><path d="M14 7h3v3"/></svg></div>
        <h3>Brand kit</h3><p>Your logo sits in the top right corner as a watermark, and your preferred settings become the default for every new project.</p>
      </li>
    </ul>
  </section>

  <section class="wl-sec">
    <div class="wl-sec-head wl-reveal">
      <h2>From clip to post</h2>
      <p>Send now, schedule, or let Autopilot place the clips you approve into daily time slots. Everything goes through accounts you connect yourself.</p>
    </div>
    <div class="wl-lead-feats one">
      <div class="wl-lead-feat wide wl-reveal">
        <div class="wl-feat-ic" aria-hidden="true"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6-6 6 6"/><path d="M12 3v13"/><path d="M4 21h16"/></svg></div>
        <h3>One panel for five platforms</h3><p>Tick TikTok, YouTube Shorts, Facebook Reels, Instagram Reels and Threads, write the caption once, then send now or pick a date and time. Each clip goes to the default account you set on the Connected accounts page. TikTok can receive it as a draft in your inbox or as a direct post.</p>
      </div>
    </div>
    <ul class="wl-feats">
      <li class="wl-feat wl-reveal">
        <div class="wl-feat-ic" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 007.5.5l3-3a5 5 0 00-7-7l-1.7 1.7"/><path d="M14 11a5 5 0 00-7.5-.5l-3 3a5 5 0 007 7l1.7-1.7"/></svg></div>
        <h3>Connected accounts</h3><p>One page for every publishing account, with one default per platform. If the default is disconnected, no clip moves to another account until you choose one.</p>
      </li>
      <li class="wl-feat wl-reveal">
        <div class="wl-feat-ic" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="17" rx="2"/><path d="M3 9h18M8 2v4M16 2v4"/></svg></div>
        <h3>Calendar</h3><p>Schedule posts to all five platforms in one calendar. A worker on the server sends each one when its time comes.</p>
      </li>
      <li class="wl-feat wl-reveal">
        <div class="wl-feat-ic" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 11-3-6.7L21 8"/><path d="M21 3v5h-5"/></svg></div>
        <h3>Autopilot with review</h3><p>Watch a YouTube channel or keyword. New videos become clips and wait in a review queue. A clip you approve takes the next daily slot on YouTube, Facebook, Instagram and Threads (the daily limit is yours to set), or goes straight to your default TikTok account, only on the platforms you switched on.</p>
      </li>
      <li class="wl-feat wl-reveal">
        <div class="wl-feat-ic" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 20V10M12 20V4M20 20v-7"/></svg></div>
        <h3>Hook A/B tests</h3><p>Pit your current hook against two or three new ones. Every version is posted to one TikTok account at the same hour, one per day, and the winner is chosen by views at the same post age.</p>
      </li>
      <li class="wl-feat wl-reveal">
        <div class="wl-feat-ic" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 9l-4 3 4 3M16 9l4 3-4 3M14 5l-4 14"/></svg></div>
        <h3>REST API and MCP</h3><p>Create tokens with read, render or publish abilities. An AI agent or script can create projects, render clips and upload to TikTok or YouTube.</p>
      </li>
      <li class="wl-feat wl-reveal">
        <div class="wl-feat-ic" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg></div>
        <h3>Monthly render quota</h3><p>Free accounts get a monthly quota of render minutes, and an admin can move an account to the Pro plan. Your usage is shown on the Plans page.</p>
      </li>
    </ul>
  </section>

  <section class="wl-sec">
    <div class="wl-sec-head wl-reveal"><h2>How it works</h2></div>
    <div class="wl-steps">
      <div class="wl-step wl-reveal"><h3>Paste a YouTube link</h3><p>Use a video you own or have permission to use. Or set up Autopilot to watch a channel.</p></div>
      <div class="wl-step wl-reveal"><h3>Pick, adjust, render</h3><p>Choose the suggested moments, adjust where each one starts and ends on the transcript, set the style, then render one clip or all of them.</p></div>
      <div class="wl-step wl-reveal"><h3>Send, schedule or download</h3><p>Send to the platforms you tick, schedule in the calendar, or download every clip with its caption in one ZIP.</p></div>
    </div>
  </section>

  <section class="wl-sec" id="data">
    <div class="wl-box wl-reveal">
      <div class="wl-box-ic"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true" stroke-width="2" stroke-linecap="round"><path d="M10 13a5 5 0 007.5.5l3-3a5 5 0 00-7-7l-1.7 1.7"/><path d="M14 11a5 5 0 00-7.5-.5l-3 3a5 5 0 007 7l1.7-1.7"/></svg></div>
      <div>
        <h2>How Clipper Studio uses TikTok, YouTube and Meta data</h2>
        <p>You connect your own accounts through each platform's authorization screen. A clip is uploaded only to an account you connected, and only after you ask for it: you click send, you schedule it in the calendar, you approve it in the Autopilot queue (for platforms where you switched Autopilot on), or an API token you created with the publish ability sends it. Without one of these, nothing is posted.</p>

        <h3>TikTok</h3>
        <ul>
          <li><strong>Login Kit</strong>: your TikTok display name and avatar, used only to show which account is connected.</li>
          <li><strong>Content Posting API</strong>: a clip is sent to your TikTok inbox as a draft to finish in TikTok, or posted directly with the caption, privacy and interaction settings you choose on the send screen.</li>
          <li><strong>video.list</strong>: after a clip is posted through Clipper Studio, its view, like, comment and share counts are read to pick the winner of a hook A/B test and to rank later Autopilot clips.</li>
        </ul>

        <h3>YouTube</h3>
        <ul>
          <li><strong>YouTube Data API</strong>: you connect your channel through Google's authorization screen. A clip is uploaded as a Short with the title, description and visibility you set. Your channel name is read to show which channel is connected, and the status of a video you just uploaded (processing, published, failed) is checked until it settles. Clipper Studio uses YouTube API Services; see the <a href="https://www.youtube.com/t/terms" target="_blank" rel="noopener">YouTube Terms of Service</a> and the <a href="https://policies.google.com/privacy" target="_blank" rel="noopener">Google Privacy Policy</a>.</li>
        </ul>

        <h3>Facebook, Instagram and Threads (Meta)</h3>
        <ul>
          <li><strong>Facebook Login for Business</strong>: your Facebook account name and the list of Pages you can manage (<code>pages_show_list</code>, <code>pages_read_engagement</code>). You choose which Pages, and which Instagram professional accounts linked to them, to connect; only those are kept.</li>
          <li><strong>Reels</strong>: a clip is uploaded as a Reel to the Pages you chose (<code>pages_manage_posts</code>) and to the Instagram accounts you chose (<code>instagram_basic</code>, <code>instagram_content_publish</code>). Instagram and Threads fetch the video file from our server through a signed link that works only for a short time.</li>
          <li><strong>Threads</strong>: through Threads login (<code>threads_basic</code>, <code>threads_content_publish</code>), your Threads username is shown and a clip is posted as a video to that account.</li>
        </ul>

        <p>You can disconnect an account at any time on the Connected accounts page. The full rules are in the <a href="{{ route('privacy') }}">Privacy Policy</a> and the <a href="{{ route('terms') }}">Terms of Service</a>.</p>
      </div>
    </div>
  </section>

  <section class="wl-band wl-reveal">
    <h2>Ready to make your first clip?</h2>
    <p>Closed beta: sign-up needs an invitation code from the administrator.</p>
    <div class="wl-band-cta">
      @auth
        <a class="pb-btn" href="{{ route('home') }}">Open app</a>
      @else
        <a class="pb-btn" href="{{ route('login') }}">Sign in to Studio</a>
        @if (\App\Http\Controllers\Auth\RegisterController::open())
          <a class="pb-btn ghost" href="{{ route('register') }}">Sign up with an invitation code</a>
        @endif
      @endauth
    </div>
  </section>

  <script>
  (function () {
    document.documentElement.classList.add('js');
    const still = matchMedia('(prefers-reduced-motion: reduce)').matches;

    // Waveform bars for the source timeline
    const track = document.getElementById('wl-track');
    for (let i = 0; i < 64; i++) {
      const bar = document.createElement('i');
      bar.style.height = (18 + Math.abs(Math.sin(i * 1.7) * 60) + (i * 37 % 20)) + '%';
      track.insertBefore(bar, track.firstChild);
    }

    // Karaoke subtitle: highlight one word at a time, then move to the next line
    const lines = ['Start small every single day', 'Consistency beats motivation', 'That is the whole secret'];
    const sub = document.getElementById('wl-sub');
    let line = 0, word = 0;
    const draw = () => {
      const words = lines[line].split(' ');
      sub.innerHTML = words.map((w, i) => i === word ? '<span class="on">' + w + '</span>' : w).join(' ');
    };
    draw();
    if (!still) {
      setInterval(() => {
        word++;
        if (word >= lines[line].split(' ').length) { word = 0; line = (line + 1) % lines.length; }
        draw();
      }, 420);
    }

    // The toast alternates between the two places a clip can go
    const toast = document.getElementById('wl-toast');
    const toasts = [['Draft sent to TikTok', 'Ready to edit in your inbox'], ['Uploaded to YouTube Shorts', 'Now on your channel'], ['Scheduled for Instagram Reels', 'Goes out at the time you picked']];
    let t = 0;
    toast.addEventListener('animationiteration', () => {
      t = (t + 1) % toasts.length;
      toast.querySelector('div > b').textContent = toasts[t][0];
      toast.querySelector('div > span').textContent = toasts[t][1];
    });

    // Fade sections in as they scroll into view
    const io = new IntersectionObserver(entries => entries.forEach(e => {
      if (e.isIntersecting) { e.target.classList.add('in'); io.unobserve(e.target); }
    }), { threshold: .15 });
    const reveal = document.querySelectorAll('.wl-reveal');
    reveal.forEach(el => io.observe(el));
    // Never leave content hidden if the observer doesn't fire (old browsers, page capture tools)
    setTimeout(() => reveal.forEach(el => el.classList.add('in')), 1500);
  })();
  </script>
@endsection
