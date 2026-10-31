<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>Brand Kit — Clipper Studio</title>
@include('partials.favicon')
<meta name="csrf-token" content="{{ csrf_token() }}" />
@include('partials.head')
@vite('resources/css/studio.css')
@include('partials.session-guard')
<style>
  .br-wrap { max-width: 720px; margin: 0 auto; padding: 40px 24px 96px; }
  .br-wrap h1 { font-size: 28px; font-weight: 700; letter-spacing: -.02em; margin: 0 0 8px; }
  .br-wrap .lead { color: var(--text-dim); margin: 0 0 28px; }
  .br-logo { display: flex; align-items: center; gap: 16px; }
  .br-logo img { width: 96px; height: 96px; object-fit: contain; border-radius: 12px; background: var(--surface-2, #0001); }
  .br-row { display: flex; gap: 16px; flex-wrap: wrap; }
  .br-row > * { flex: 1 1 200px; }
  .br-color input[type=color] { width: 100%; height: 44px; padding: 4px; border: 1px solid var(--border); border-radius: 10px; background: none; cursor: pointer; }
  .br-status { padding: 10px 14px; border-radius: 10px; background: var(--accent-soft); margin-bottom: 20px; }
  .br-actions { display: flex; gap: 12px; align-items: center; margin-top: 28px; }
  .br-errors { color: var(--danger, #ef4444); font-size: 13px; margin-top: 6px; }
</style>
</head>
<body>
<div class="br-wrap">
  <a href="{{ route('home') }}" class="btn-ghost btn-sm">← Kembali ke Studio</a>
  <h1 style="margin-top:16px">Brand Kit</h1>
  <p class="lead">Identitas visual default untuk setiap project baru. Bisa diubah lagi per project di Studio.</p>

  @if (session('status'))
    <div class="br-status">{{ session('status') }}</div>
  @endif

  <div class="fblock">
    <div class="fblock-title" style="margin-bottom:10px">Logo <span style="color:var(--text-dim);font-weight:400;font-size:12px">· PNG/JPG, maks 2 MB. Tampil sebagai watermark di pojok kanan atas.</span></div>
    <div class="br-logo">
      @if ($kit->logo_path)
        <img src="{{ route('brand.logo') }}" alt="Logo brand" />
        <form method="POST" action="{{ route('brand.logo.destroy') }}">
          @csrf
          @method('DELETE')
          <button type="submit" class="btn-secondary btn-sm">Hapus logo</button>
        </form>
      @else
        <span class="fhint">Belum ada logo.</span>
      @endif
    </div>
  </div>

  <form method="POST" action="{{ route('brand.update') }}" enctype="multipart/form-data" style="margin-top:24px">
    @csrf
    @method('PUT')

    <div class="fblock">
      <div class="fblock-title" style="margin-bottom:10px">Ganti logo</div>
      <input type="file" name="logo" accept="image/png,image/jpeg" class="select" />
      @error('logo') <div class="br-errors">{{ $message }}</div> @enderror
    </div>

    <div class="fblock br-row" style="margin-top:24px">
      <div class="br-color">
        <div class="fblock-title" style="margin-bottom:10px">Warna utama <span style="color:var(--text-dim);font-weight:400;font-size:12px">· kata penting</span></div>
        <input type="color" name="primary_color" value="{{ $kit->primary_color ?? '#facc15' }}" />
        @error('primary_color') <div class="br-errors">{{ $message }}</div> @enderror
      </div>
      <div class="br-color">
        <div class="fblock-title" style="margin-bottom:10px">Warna teks <span style="color:var(--text-dim);font-weight:400;font-size:12px">· teks dasar</span></div>
        <input type="color" name="secondary_color" value="{{ $kit->secondary_color ?? '#ffffff' }}" />
        @error('secondary_color') <div class="br-errors">{{ $message }}</div> @enderror
      </div>
    </div>

    <div class="fblock" style="margin-top:24px">
      <div class="fblock-title" style="margin-bottom:10px">Font</div>
      <select class="select" name="font_family">
        <option value="" @selected(! $kit->font_family)>Default (Montserrat)</option>
        @foreach ($fontFamilies as $font)
          <option value="{{ $font }}" @selected($kit->font_family === $font)>{{ $font }}</option>
        @endforeach
      </select>
      @error('font_family') <div class="br-errors">{{ $message }}</div> @enderror
    </div>

    <div class="fblock" style="margin-top:24px">
      <div class="fblock-title" style="margin-bottom:10px">Latar hook</div>
      <select class="select" name="hook_style">
        @foreach (['none' => 'Tanpa latar', 'semi' => 'Semi transparan', 'full' => 'Penuh'] as $value => $label)
          <option value="{{ $value }}" @selected($kit->hook_style === $value || (! $kit->exists && $value === 'semi'))>{{ $label }}</option>
        @endforeach
      </select>
      @error('hook_style') <div class="br-errors">{{ $message }}</div> @enderror
    </div>

    <div class="br-actions">
      <button type="submit" class="btn">Simpan</button>
    </div>
  </form>
</div>
</body>
</html>
