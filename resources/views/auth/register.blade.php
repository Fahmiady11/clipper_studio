<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>Daftar — Clipper Studio</title>
<link rel="preconnect" href="https://fonts.googleapis.com" />
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet" />
<style>
:root {
  --bg:#0a0a0c; --surface:#131318; --surface-2:#1a1a21; --surface-3:#22222b;
  --border:#25252e; --border-strong:#34343f;
  --text:#f5f5f7; --text-muted:#8a8a95; --text-dim:#55555f;
  --teal:#2dd4bf; --teal-dim:rgba(45,212,191,.12);
  --radius:14px; --radius-sm:8px; --radius-lg:20px;
  font-family:'Inter',ui-sans-serif,system-ui,sans-serif;
}
*{box-sizing:border-box}
html,body{margin:0;padding:0;background:var(--bg);color:var(--text);-webkit-font-smoothing:antialiased;font-family:'Inter',ui-sans-serif,sans-serif;min-height:100vh}
.grain{position:fixed;inset:0;pointer-events:none;z-index:0;opacity:.4;mix-blend-mode:overlay;background-image:radial-gradient(rgba(255,255,255,.04) 1px,transparent 1px);background-size:3px 3px}
.shell{min-height:100vh;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:24px;position:relative;z-index:1}
.brand{display:flex;align-items:center;gap:10px;font-weight:600;font-size:15px;letter-spacing:-.01em;margin-bottom:36px}
.brand-mark{width:22px;height:22px;border-radius:6px;background:linear-gradient(135deg,var(--teal),#60a5fa);position:relative}
.brand-mark::after{content:'';position:absolute;inset:5px;background:var(--bg);border-radius:3px;clip-path:polygon(20% 0,100% 50%,20% 100%)}
.card{width:100%;max-width:400px;background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-lg);padding:32px}
.card-title{font-size:22px;font-weight:600;letter-spacing:-.02em;margin:0 0 6px}
.card-sub{font-size:14px;color:var(--text-muted);margin:0 0 28px}
.fblock{margin-bottom:20px}
.flabel{display:block;font-size:13px;font-weight:500;margin-bottom:8px;color:var(--text)}
.field{width:100%;background:var(--surface-2);border:1px solid var(--border-strong);border-radius:10px;color:var(--text);font-family:inherit;font-size:14px;padding:12px 14px;outline:none;transition:border-color .15s}
.field:focus{border-color:var(--teal);box-shadow:0 0 0 3px rgba(45,212,191,.08)}
.field.error{border-color:#ef4444}
.err{font-size:12.5px;color:#f87171;margin-top:6px}
.btn{display:flex;align-items:center;justify-content:center;width:100%;background:var(--text);color:var(--bg);border:0;border-radius:12px;padding:14px;font-size:14.5px;font-weight:600;letter-spacing:-.01em;cursor:pointer;font-family:inherit;transition:transform .1s,opacity .15s;margin-top:24px}
.btn:hover{transform:translateY(-1px)}
.btn:active{transform:translateY(0)}
.footer{margin-top:20px;text-align:center;font-size:13px;color:var(--text-muted)}
.footer a{color:var(--teal);text-decoration:none}
.footer a:hover{text-decoration:underline}
.notice{font-size:13px;line-height:1.45;color:var(--teal);background:var(--teal-dim);border:1px solid rgba(45,212,191,.25);border-radius:10px;padding:10px 14px;margin:0 0 20px}
.btn[disabled]{opacity:.6;cursor:default;transform:none}
@media(max-width:480px){.shell{padding:20px 16px;justify-content:flex-start;padding-top:48px}.card{padding:24px 20px}.brand{margin-bottom:28px}}
</style>
</head>
<body>
<div class="grain"></div>
<div class="shell">
  <div class="brand">
    <div class="brand-mark"></div>
    Clipper <span style="color:var(--text-muted);font-weight:500">Studio</span>
  </div>
  <div class="card">
    <h1 class="card-title">Buat akun baru</h1>
    <p class="card-sub">Gratis. Tidak perlu kartu kredit.</p>

    @if (session('status'))
      <div class="notice" role="status">{{ session('status') }}</div>
    @endif

    <form method="POST" action="{{ route('register') }}" onsubmit="this.querySelector('[type=submit]').disabled = true">
      @csrf

      <div class="fblock">
        <label class="flabel" for="name">Nama lengkap</label>
        <input class="field {{ $errors->has('name') ? 'error' : '' }}"
               id="name" name="name" type="text" autocomplete="name"
               value="{{ old('name') }}" placeholder="Nama kamu" required />
        @error('name')
          <div class="err">{{ $message }}</div>
        @enderror
      </div>

      <div class="fblock">
        <label class="flabel" for="email">Email</label>
        <input class="field {{ $errors->has('email') ? 'error' : '' }}"
               id="email" name="email" type="email" autocomplete="email"
               value="{{ old('email') }}" placeholder="kamu@email.com" required />
        @error('email')
          <div class="err">{{ $message }}</div>
        @enderror
      </div>

      <div class="fblock">
        <label class="flabel" for="password">Password</label>
        <input class="field {{ $errors->has('password') ? 'error' : '' }}"
               id="password" name="password" type="password" autocomplete="new-password"
               placeholder="Min. 8 karakter" required />
        @error('password')
          <div class="err">{{ $message }}</div>
        @enderror
      </div>

      <div class="fblock">
        <label class="flabel" for="password_confirmation">Konfirmasi password</label>
        <input class="field"
               id="password_confirmation" name="password_confirmation" type="password"
               autocomplete="new-password" placeholder="Ulangi password" required />
      </div>

      <button class="btn" type="submit">Buat akun</button>
    </form>

    <div class="footer">
      Sudah punya akun? <a href="{{ route('login') }}">Masuk</a>
    </div>
  </div>
</div>
</body>
</html>
