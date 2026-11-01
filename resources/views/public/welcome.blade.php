@extends('public._layout')
@section('title', 'Turn long videos into TikTok and YouTube Shorts clips')
@section('main_class', 'wide')

@section('style')
/* ── Hero ─────────────────────────────────────────────────────────────── */
.wl-hero { position: relative; display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1.05fr); gap: 48px; align-items: center; padding: 16px 0 56px; }
.wl-hero h1 { font-size: 64px; font-weight: 800; line-height: 1.02; letter-spacing: -.045em; margin: 0 0 22px; text-wrap: balance; }
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
.wl-box ul { margin: 0; padding-left: 18px; }
.wl-box li { font-size: 14.5px; line-height: 1.7; color: var(--text-muted); }
.wl-box li strong { color: var(--text); }
.wl-band { margin-top: 72px; text-align: center; padding: 44px 24px; border-radius: 24px; background: var(--accent); color: var(--on-accent); }
.wl-band h2 { font-size: 40px; font-weight: 800; letter-spacing: -.04em; margin: 0 0 8px; }
.wl-band p { margin: 0 0 22px; opacity: .8; }
.wl-band .pb-btn { min-height: 48px; padding: 12px 22px; font-size: 15px; color: var(--accent); background: var(--on-accent); }

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
      <h1>Turn long videos into <mark>short clips</mark> for TikTok and Shorts</h1>
      <p class="wl-lead">Clipper Studio finds the strongest moments in a long video or podcast and edits them into 9:16 clips: pauses cut out, faces followed, karaoke subtitles, an opening hook and B-roll. Then it sends the clip you choose to TikTok or YouTube Shorts.</p>
      <div class="wl-cta">
        @auth
          <a class="pb-btn" href="{{ route('home') }}">Open app</a>
        @else
          <a class="pb-btn" href="{{ route('login') }}">Sign in</a>
          <small>Access by invitation</small>
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
      <h2>Everything a clip needs</h2>
      <p>From one long video to ready-to-post vertical clips in a few minutes.</p>
    </div>
    <div class="wl-lead-feats">
      <div class="wl-lead-feat wl-reveal">
        <div class="wl-feat-ic" aria-hidden="true"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12v0M6 9v6M9 6v12M15 8v8M18 10v4M21 12v0"/><rect x="11" y="3" width="2" height="18" rx="1"/></svg></div>
        <h3>AI picks the moments</h3><p>The transcript is read to find the parts most likely to keep viewers watching, and each clip starts and ends on a full sentence.</p>
      </div>
      <div class="wl-lead-feat wl-reveal">
        <div class="wl-feat-ic" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7V5a2 2 0 012-2h2M17 3h2a2 2 0 012 2v2M21 17v2a2 2 0 01-2 2h-2M7 21H5a2 2 0 01-2-2v-2"/><circle cx="12" cy="10" r="3"/><path d="M7.5 17a5 5 0 019 0"/></svg></div>
        <h3>Face tracking</h3><p>The 9:16 frame follows faces and cuts to whoever is talking, so the speaker never drifts out of shot. Or keep the full shot over a blurred background.</p>
      </div>
    </div>
    <ul class="wl-feats">
      <li class="wl-feat wl-reveal">
        <div class="wl-feat-ic" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 4v16M18 4v16"/><path d="M10 9l4 3-4 3"/></svg></div>
        <h3>Jump cuts and zoom</h3><p>Long pauses and filler words like "um" are cut out, and the picture punches in at cuts and sentence ends, as an editor would.</p>
      </li>
      <li class="wl-feat wl-reveal">
        <div class="wl-feat-ic" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M7 15h4M13 15h4M7 11h10"/></svg></div>
        <h3>Karaoke subtitles</h3><p>Word-by-word highlighting in ready-made styles or your own font, colour and position, with emoji on key words.</p>
      </li>
      <li class="wl-feat wl-reveal">
        <div class="wl-feat-ic" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7V4h16v3M9 20h6M12 4v16"/></svg></div>
        <h3>Hook, title and progress bar</h3><p>A headline pops in for the first seconds, the topic can stay on top, and a thin bar shows how much is left.</p>
      </li>
      <li class="wl-feat wl-reveal">
        <div class="wl-feat-ic" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="6" width="14" height="12" rx="2"/><path d="M16 10l6-3v10l-6-3"/></svg></div>
        <h3>Automatic B-roll</h3><p>Short stock clips that match what is being said are cut in over the speaker, under the subtitles.</p>
      </li>
      <li class="wl-feat wl-reveal">
        <div class="wl-feat-ic" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/></svg></div>
        <h3>Music and clean sound</h3><p>Licensed tracks by mood, mixed well under the voice; without music the voice is levelled to platform loudness.</p>
      </li>
      <li class="wl-feat wl-reveal">
        <div class="wl-feat-ic" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6-6 6 6"/><path d="M12 3v13"/><path d="M4 21h16"/></svg></div>
        <h3>Post to TikTok and YouTube</h3><p>Send a clip to your TikTok inbox as a draft, post it directly, or upload it to your YouTube channel as a Short.</p>
      </li>
      <li class="wl-feat wl-reveal">
        <div class="wl-feat-ic" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 11-3-6.7L21 8"/><path d="M21 3v5h-5"/></svg></div>
        <h3>Autopilot with review</h3><p>New videos from your sources become clips automatically; nothing goes out until you approve it.</p>
      </li>
    </ul>
  </section>

  <section class="wl-sec">
    <div class="wl-sec-head wl-reveal"><h2>How it works</h2></div>
    <div class="wl-steps">
      <div class="wl-step wl-reveal"><h3>Paste a video link</h3><p>Add a long-form video you own or have permission to use.</p></div>
      <div class="wl-step wl-reveal"><h3>Pick and render clips</h3><p>Choose the suggested moments, adjust where each one starts and ends, set the style, then render one clip or all of them.</p></div>
      <div class="wl-step wl-reveal"><h3>Post or download</h3><p>Send a clip to TikTok or YouTube Shorts, or download every clip with its caption in one ZIP.</p></div>
    </div>
  </section>

  <section class="wl-sec">
    <div class="wl-box wl-reveal">
      <div class="wl-box-ic"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true" stroke-width="2" stroke-linecap="round"><path d="M10 13a5 5 0 007.5.5l3-3a5 5 0 00-7-7l-1.7 1.7"/><path d="M14 11a5 5 0 00-7.5-.5l-3 3a5 5 0 007 7l1.7-1.7"/></svg></div>
      <div>
        <h2>How Clipper Studio works with TikTok and YouTube</h2>
        <ul>
          <li><strong>Login Kit</strong>: you connect your own TikTok account through TikTok's authorization screen. We use your display name and avatar only to show which account is connected.</li>
          <li><strong>Content Posting API</strong>: a clip is uploaded only when you click upload for that clip. Send it to your TikTok inbox as a draft to finish in TikTok, or post it directly with the caption, privacy and interaction settings you choose on that screen.</li>
          <li><strong>YouTube Data API</strong>: you connect your own channel through Google's authorization screen. A clip is uploaded only when you click upload for that clip, with the title, description and visibility you set. Clipper Studio uses YouTube API Services; see the <a href="https://www.youtube.com/t/terms" target="_blank" rel="noopener">YouTube Terms of Service</a>.</li>
          <li>Nothing is posted without your action for that clip, and you can disconnect your accounts at any time. See our <a href="{{ route('privacy') }}">Privacy Policy</a>.</li>
        </ul>
      </div>
    </div>
  </section>

  <section class="wl-band wl-reveal">
    <h2>Ready to make your first clip?</h2>
    <p>Access is by invitation: accounts are created by the administrator.</p>
    @auth
      <a class="pb-btn" href="{{ route('home') }}">Open app</a>
    @else
      <a class="pb-btn" href="{{ route('login') }}">Sign in</a>
    @endauth
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
    const toasts = [['Draft sent to TikTok', 'Ready to edit in your inbox'], ['Uploaded to YouTube Shorts', 'Live on your channel']];
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
