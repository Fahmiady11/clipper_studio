<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>Akun · Clipper Studio</title>
@include('partials.favicon')
<meta name="csrf-token" content="{{ csrf_token() }}" />
@include('partials.head')
@vite('resources/css/studio.css')
@include('partials.session-guard')
<style>
  /* Same frame as the other settings pages. The delete card is the one thing in danger red:
     its border and its button, so the irreversible action never borrows the lime of normal actions. */
  .ak-wrap { max-width: 720px; margin: 0 auto; padding: 40px 24px 96px; }
  .ak-wrap h1 { font-size: 28px; font-weight: 700; letter-spacing: -.02em; margin: 16px 0 8px; }
  .ak-wrap .lead { color: var(--text-muted); font-size: 14px; line-height: 1.6; margin: 0 0 28px; }
  .ak-who { display: grid; grid-template-columns: 140px minmax(0, 1fr); gap: 10px 16px; margin: 0; padding: 16px 18px; background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); font-size: 14px; }
  .ak-who dt { color: var(--text-muted); }
  .ak-who dd { margin: 0; overflow-wrap: anywhere; }
  .ak-danger { margin-top: 40px; border: 1px solid var(--danger); border-radius: var(--radius); padding: 20px 22px 22px; }
  .ak-danger h2 { font-size: 18px; margin: 0 0 6px; color: var(--danger); }
  .ak-danger p, .ak-danger li { font-size: 14px; line-height: 1.6; color: var(--text-muted); }
  .ak-danger p { margin: 0 0 10px; }
  .ak-danger strong { color: var(--text); font-weight: 600; }
  .ak-danger ul { margin: 0 0 12px; padding-left: 20px; }
  .ak-danger a { color: var(--accent-ink); }
  .ak-form { display: flex; flex-direction: column; gap: 16px; margin-top: 18px; padding-top: 18px; border-top: 1px solid var(--border); }
  .ak-field label { display: block; font-size: 13px; font-weight: 600; margin-bottom: 6px; }
  .ak-field label code { font-family: var(--font-mono); font-size: 13px; color: var(--danger); }
  .ak-input { width: 100%; max-width: 360px; min-height: 44px; background: var(--surface); border: 1px solid var(--border-strong); border-radius: 10px; color: var(--text); font: inherit; font-size: 15px; padding: 10px 14px; }
  .ak-input:focus-visible { outline: 2px solid var(--accent-ink); outline-offset: 1px; }
  .ak-input[aria-invalid=true] { border-color: var(--danger); }
  .ak-err { color: var(--danger); font-size: 13px; margin-top: 6px; }
  .ak-btn { align-self: flex-start; background: var(--danger); color: var(--bg); }
  .ak-danger .ak-note { font-size: 14px; line-height: 1.6; background: var(--surface-2); border: 1px solid var(--border); border-radius: 10px; padding: 12px 14px; margin: 14px 0 0; color: var(--text); }
  @media (max-width: 480px) {
    .ak-wrap { padding: 24px 16px 72px; }
    .ak-who { grid-template-columns: 1fr; gap: 2px; }
    .ak-who dd + dt { margin-top: 10px; }
    .ak-danger { padding: 18px 16px; }
    .ak-input { max-width: none; }
    .ak-btn { align-self: stretch; white-space: normal; }
  }
</style>
</head>
<body>
<div class="ak-wrap">
  <a href="{{ route('home') }}" class="btn-ghost btn-sm">← Kembali ke Studio</a>
  <h1>Akun</h1>
  <p class="lead">Akun yang sedang masuk, dan tempat menghapusnya kalau kamu berhenti memakai Clipper.</p>

  <dl class="ak-who">
    <dt>Nama</dt>
    <dd>{{ $user->name }}</dd>
    <dt>Email</dt>
    <dd>{{ $user->email }}</dd>
    <dt>Terdaftar sejak</dt>
    <dd>{{ $user->created_at?->locale('id')->isoFormat('D MMMM YYYY') }}</dd>
  </dl>

  <section class="ak-danger" aria-labelledby="ak-delete-title">
    <h2 id="ak-delete-title">Hapus akun</h2>
    <p>Akun dan datanya langsung dihapus begitu kamu menekan tombol di bawah. <strong>Tidak ada masa tunggu, dan tidak bisa dipulihkan.</strong></p>
    <p>Yang ikut terhapus:</p>
    <ul>
      <li>Semua project, transkrip, dan klip hasil render beserta filenya</li>
      <li>Jadwal posting yang belum jalan, sumber Autopilot, dan antrean review</li>
      <li>Brand kit, logo, dan footage gameplay yang kamu unggah</li>
      <li>Token API dan catatan pemakaian kuota</li>
      <li>Semua <a href="{{ route('accounts.page') }}">akun terhubung</a>. Clipper juga meminta Google (YouTube), TikTok, dan Facebook mencabut izin aplikasinya. Untuk Threads, hapus Clipper sendiri dari aplikasi terhubung di pengaturan Threads.</li>
    </ul>
    <p>Video yang sudah terposting di TikTok, YouTube, Facebook, Instagram, atau Threads tetap ada di sana. Hapus langsung di platformnya kalau perlu.</p>

    @if ($isLastAdmin)
      <p class="ak-note" role="note">Kamu satu-satunya admin, jadi akun ini tidak bisa dihapus dari sini. Tanpa admin, tidak ada yang bisa menyetujui pendaftaran baru.</p>
    @else
      {{-- Drop the studio draft too, like logging out does --}}
      <form class="ak-form" method="POST" action="{{ route('account.destroy') }}" onsubmit="try { localStorage.removeItem('clipper_studio_v1') } catch (e) {}">
        @csrf
        @method('DELETE')

        @error('account', 'deleteAccount')
          <p class="ak-note" role="alert">{{ $message }}</p>
        @enderror

        <div class="ak-field">
          <label for="ak-password">Password</label>
          <input id="ak-password" class="ak-input" type="password" name="password" required autocomplete="current-password"
            @error('password', 'deleteAccount') aria-invalid="true" aria-describedby="ak-password-err" @enderror />
          @error('password', 'deleteAccount') <div class="ak-err" id="ak-password-err">{{ $message }}</div> @enderror
        </div>

        <div class="ak-field">
          <label for="ak-confirm">Ketik <code>{{ $confirmWord }}</code> untuk konfirmasi</label>
          <input id="ak-confirm" class="ak-input" type="text" name="confirmation" required autocomplete="off" autocapitalize="characters" spellcheck="false"
            @error('confirmation', 'deleteAccount') aria-invalid="true" aria-describedby="ak-confirm-err" @enderror />
          @error('confirmation', 'deleteAccount') <div class="ak-err" id="ak-confirm-err">{{ $message }}</div> @enderror
        </div>

        <button type="submit" class="btn ak-btn">Hapus akun dan semua data</button>
      </form>
    @endif
  </section>
</div>
</body>
</html>
