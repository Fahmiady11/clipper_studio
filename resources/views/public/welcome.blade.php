@extends('public._layout')
@section('title', 'Turn long videos into TikTok clips')

@section('style')
.wl-hero { text-align: center; padding: 24px 0 40px; }
.wl-hero h1 { font-size: 44px; line-height: 1.1; letter-spacing: -.03em; margin: 0 0 16px; }
.wl-hero h1 span { color: var(--teal); }
.wl-hero p { font-size: 17px; line-height: 1.6; color: var(--text-muted); max-width: 620px; margin: 0 auto 28px; }
.wl-cta { display: flex; gap: 10px; justify-content: center; flex-wrap: wrap; }
.wl-steps { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 14px; margin-top: 24px; }
.wl-step { background: var(--surface); border: 1px solid var(--border); border-radius: 14px; padding: 20px; }
.wl-step i { font-style: normal; font-family: 'JetBrains Mono', monospace; font-size: 11px; font-weight: 600; color: var(--teal); background: var(--teal-dim); border-radius: 6px; padding: 2px 7px; }
.wl-step h3 { font-size: 16px; margin: 12px 0 6px; }
.wl-step p { font-size: 14px; line-height: 1.6; color: var(--text-muted); margin: 0; }
.wl-box { margin-top: 40px; background: var(--surface); border: 1px solid var(--border); border-radius: 14px; padding: 24px; }
.wl-box h2 { font-size: 18px; margin: 0 0 10px; }
.wl-box p, .wl-box li { font-size: 14.5px; line-height: 1.7; color: var(--text-muted); }
.wl-box ul { margin: 0; padding-left: 20px; }
.wl-note { margin-top: 24px; text-align: center; font-size: 13px; color: var(--text-dim); }
@media (max-width: 720px) {
  .wl-hero h1 { font-size: 32px; }
  .wl-steps { grid-template-columns: minmax(0, 1fr); }
}
@endsection

@section('content')
  <section class="wl-hero">
    <h1>Turn long videos into <span>short vertical clips</span></h1>
    <p>Clipper Studio finds the strongest moments in a long video or podcast, turns them into 9:16 clips with subtitles and an opening hook, and sends the clip you choose to your TikTok inbox as a draft.</p>
    <div class="wl-cta">
      @auth
        <a class="pb-btn" href="{{ route('home') }}">Open app</a>
      @else
        <a class="pb-btn" href="{{ route('login') }}">Sign in</a>
      @endauth
    </div>
  </section>

  <section class="wl-steps">
    <div class="wl-step"><i>01</i><h3>Paste a video link</h3><p>Add a link to a long-form video you own or have permission to use.</p></div>
    <div class="wl-step"><i>02</i><h3>Get clips with subtitles</h3><p>AI suggests the best moments; you pick the layout, subtitle style and hook, then render.</p></div>
    <div class="wl-step"><i>03</i><h3>Send to TikTok as a draft</h3><p>Connect your TikTok account and upload a finished clip to your inbox, then edit and post it in TikTok.</p></div>
  </section>

  <section class="wl-box">
    <h2>How Clipper Studio works with TikTok</h2>
    <ul>
      <li><strong>Login Kit</strong>: you connect your own TikTok account through TikTok's authorization screen. We use your display name and avatar only to show which account is connected.</li>
      <li><strong>Content Posting API</strong>: a clip is uploaded only when you click upload for that clip. It arrives in your TikTok inbox as a draft; you write the caption and decide whether to post it.</li>
      <li>We never post publicly on your behalf, and you can disconnect your TikTok account at any time.</li>
    </ul>
  </section>

  <p class="wl-note">Access is by invitation: accounts are created by the administrator.</p>
@endsection
