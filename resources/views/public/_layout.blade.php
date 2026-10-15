{{-- Public pages (landing, terms, privacy): readable without signing in. --}}
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>@yield('title') — Clipper Studio</title>
@include('partials.favicon')
<meta name="description" content="Clipper Studio turns long videos into short vertical clips with subtitles and sends them to your TikTok inbox as drafts." />
<link rel="preconnect" href="https://fonts.googleapis.com" />
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@500;600&display=swap" rel="stylesheet" />
<style>
:root {
  --bg:#0a0a0c; --surface:#131318; --surface-2:#1a1a21; --border:#25252e; --border-strong:#34343f;
  --text:#f5f5f7; --text-muted:#8a8a95; --text-dim:#55555f; --teal:#2dd4bf; --teal-dim:rgba(45,212,191,.12);
}
* { box-sizing: border-box; }
html, body { margin: 0; background: var(--bg); color: var(--text); font-family: 'Inter', ui-sans-serif, system-ui, sans-serif; -webkit-font-smoothing: antialiased; }
a { color: var(--teal); }
.pb-top { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 18px 32px; border-bottom: 1px solid var(--border); }
.pb-brand { display: flex; align-items: center; gap: 10px; font-weight: 600; font-size: 15px; color: var(--text); text-decoration: none; }
.pb-mark { width: 22px; height: 22px; background: url(/logo.svg) center / contain no-repeat; }
.pb-btn { display: inline-flex; align-items: center; gap: 8px; font-size: 14px; font-weight: 600; color: var(--bg); background: var(--text); border-radius: 10px; padding: 9px 16px; text-decoration: none; }
.pb-btn.ghost { color: var(--text); background: transparent; border: 1px solid var(--border-strong); }
.pb-main { max-width: 860px; margin: 0 auto; padding: 48px 24px 72px; }
.pb-main.wide { max-width: 1140px; }
.pb-doc h1 { font-size: 32px; letter-spacing: -.02em; margin: 0 0 6px; }
.pb-doc .pb-updated { color: var(--text-dim); font-size: 13px; margin: 0 0 32px; }
.pb-doc h2 { font-size: 18px; margin: 32px 0 10px; }
.pb-doc p, .pb-doc li { color: var(--text-muted); font-size: 15px; line-height: 1.7; }
.pb-doc ul { padding-left: 20px; }
.pb-doc strong { color: var(--text); font-weight: 600; }
.pb-foot { border-top: 1px solid var(--border); padding: 24px 32px; display: flex; flex-wrap: wrap; gap: 8px 20px; justify-content: space-between; font-size: 13px; color: var(--text-dim); }
.pb-foot nav { display: flex; gap: 18px; }
.pb-foot a { color: var(--text-muted); text-decoration: none; }
.pb-foot a:hover { color: var(--text); }
@media (max-width: 640px) {
  .pb-top, .pb-foot { padding-left: 16px; padding-right: 16px; }
  .pb-main { padding: 32px 16px 56px; }
  .pb-doc h1 { font-size: 26px; }
}
@yield('style')
</style>
</head>
<body>
  <header class="pb-top">
    <a class="pb-brand" href="{{ route('welcome') }}"><span class="pb-mark"></span>Clipper Studio</a>
    @auth
      <a class="pb-btn" href="{{ route('home') }}">Open app</a>
    @else
      <a class="pb-btn" href="{{ route('login') }}">Sign in</a>
    @endauth
  </header>

  <main class="pb-main @yield('main_class')">
    @yield('content')
  </main>

  <footer class="pb-foot">
    <span>© {{ date('Y') }} Clipper Studio</span>
    <nav>
      <a href="{{ route('welcome') }}">Home</a>
      <a href="{{ route('terms') }}">Terms of Service</a>
      <a href="{{ route('privacy') }}">Privacy Policy</a>
    </nav>
  </footer>
</body>
</html>
