{{--
  "Panduan" button for the topbar plus the how-to drawer it opens.
  Usage: @include('partials.guide', ['page' => 'studio' | 'autopilot'])
  The drawer is teleported to <body>: the topbar's backdrop-filter would
  otherwise trap a position:fixed child inside the bar.
--}}
@php
  $guides = [
    'studio' => [
      'title' => 'Panduan project baru',
      'intro' => 'Ubah satu video YouTube jadi beberapa clip vertikal 9:16 siap TikTok: AI memilih momen terbaik, lalu kamu atur tampilannya dan render.',
      'steps' => [
        ['Tempel link YouTube', 'Tempel URL video (youtube.com/watch, youtu.be, atau /shorts), lalu klik <b>Proses Link</b>. Judul, durasi, dan channel muncul kalau link valid.'],
        ['Pilih layout', '<b>Reframe Wajah</b> memotong bagian tengah video jadi vertikal, cocok untuk satu pembicara. <b>Gaussian</b> menaruh video utuh di tengah dengan latar blur, cocok kalau ada beberapa orang atau teks di layar.'],
        ['Atur jumlah & durasi clip', 'Tentukan berapa clip yang dicari AI. Durasi <b>Otomatis</b> membiarkan AI memilih panjang clip; <b>Manual</b> kalau kamu mau batas minimal dan maksimal sendiri (dalam detik).'],
        ['Subtitle', 'Subtitle karaoke: kata yang sedang diucapkan disorot. Pilih font, ukuran, warna, dan background. Posisi bisa dipilih atau <b>diseret langsung di preview</b>; hindari area bawah yang tertutup tombol TikTok.'],
        ['Hook & musik', 'Hook = teks pembuka 2–5 detik untuk menahan penonton. Biarkan AI menulisnya, atau tulis sendiri (apit kata dengan <code>*bintang*</code> untuk menyorotnya). Musik latar diambil dari <a href="/music">library musik</a> sesuai mood.'],
        ['Generate dengan AI', 'Klik <b>Generate Clip dengan AI</b>. Video diunduh, transkrip dibuat, lalu AI memilih momen paling berpotensi viral. Biasanya beberapa menit; kamu boleh pindah halaman, proses tetap jalan.'],
        ['Pilih & render clip', 'Pilih clip yang mau dibuat, lalu render (biasanya 1–5 menit per clip).'],
        ['Hasil', '<b>Download MP4</b>, salin caption & hashtag buatan AI, atau upload ke TikTok. Hook masih bisa diedit lalu render ulang tanpa mengulang analisis.'],
      ],
      'tips' => [
        'Semua project tersimpan di <b>Riwayat</b>: buka lagi untuk memilih clip lain atau melihat hasil.',
        'Upload sebagai <b>Draft</b> tidak membawa caption: salin caption dulu, lalu tempel di aplikasi TikTok.',
        'Video berdurasi 10–90 menit memberi hasil paling baik; video sangat panjang lebih lama diproses.',
      ],
    ],
    'autopilot' => [
      'title' => 'Panduan autopilot',
      'intro' => 'Autopilot mencari video baru secara berkala, membuat dan merender clip otomatis. Kamu tinggal review: setujui untuk dikirim ke TikTok, atau tolak.',
      'steps' => [
        ['Tambah sumber', 'Pilih <b>Channel</b> (mis. <code>@namachannel</code>) atau <b>Keyword</b> (mis. <i>podcast bisnis indonesia</i>), lalu klik <b>Tambah sumber</b>.'],
        ['Atur jadwal & filter', '<b>Cek tiap</b> = seberapa sering sumber dicek (jam). <b>Maks per cek</b> = video baru yang diambil sekali cek. <b>Durasi video</b> = batas panjang video sumber dalam <b>menit</b>; disarankan 10–90.'],
        ['Atur tampilan', 'Klik <b>Atur tampilan</b> untuk layout, subtitle, gaya hook, dan musik clip dari sumber itu. Teks hook selalu ditulis AI per clip.'],
        ['Tunggu clip', 'Sumber dicek otomatis sesuai jadwal, atau klik <b>Cek sekarang</b>. Clip muncul di <b>Antrian review</b> dan dirender otomatis.'],
        ['Review', 'Tonton preview tiap clip. <b>Approve → TikTok</b> mengirimnya sebagai draft ke akun TikTok yang dipilih di "Kirim ke". <b>Reject</b> menolak clip dan menghapus filenya supaya disk tidak penuh.'],
        ['Salin caption', 'Draft TikTok tidak membawa caption. Pakai <b>Salin semua</b> (caption + hashtag) di kartu clip, lalu tempel saat memposting draft di aplikasi TikTok.'],
      ],
      'tips' => [
        'Pakai filter <b>Belum direview / Disetujui / Ditolak</b> di atas antrian untuk melihat clip per status.',
        'Matikan sumber dengan tombol on/off kalau sedang tidak mau ada clip baru; sumbernya tidak terhapus.',
        'Hubungkan akun TikTok dulu (menu "Hubungkan akun" di bagian review) supaya tombol Approve aktif.',
      ],
    ],
  ];
  $g = $guides[$page];
@endphp

<div x-data="{ guideOpen: false }" @keydown.escape.window="guideOpen = false" style="display:contents">
  <a href="#" @click.prevent="guideOpen = true" aria-label="Panduan"><span class="lbl-long">Panduan</span><span class="lbl-short">?</span></a>

  <template x-teleport="body">
    <div class="gd-bg" x-show="guideOpen" x-transition.opacity @click.self="guideOpen = false" x-cloak>
      <aside class="gd-panel" role="dialog" aria-modal="true" aria-labelledby="gd-title"
             x-show="guideOpen" x-transition:enter="gd-enter" x-transition:enter-start="gd-from" x-transition:leave="gd-enter" x-transition:leave-end="gd-from">
        <div class="gd-head">
          <h2 id="gd-title">{{ $g['title'] }}</h2>
          <button type="button" class="gd-close" @click="guideOpen = false" aria-label="Tutup panduan">✕</button>
        </div>
        <div class="gd-body">
          <p class="gd-intro">{{ $g['intro'] }}</p>
          <ol class="gd-steps">
            @foreach ($g['steps'] as [$title, $body])
              <li><b>{{ $title }}</b><p>{!! $body !!}</p></li>
            @endforeach
          </ol>
          <div class="gd-tips">
            <b>Tips</b>
            <ul>
              @foreach ($g['tips'] as $tip)
                <li>{!! $tip !!}</li>
              @endforeach
            </ul>
          </div>
        </div>
      </aside>
    </div>
  </template>
</div>

@once
<style>
  .gd-bg { position: fixed; inset: 0; z-index: 200; background: rgba(0, 0, 0, .55); backdrop-filter: blur(3px); display: flex; justify-content: flex-end; }
  .gd-panel { width: min(440px, 100%); height: 100%; background: var(--surface); border-left: 1px solid var(--border-strong); display: flex; flex-direction: column; box-shadow: -20px 0 60px rgba(0, 0, 0, .5); }
  .gd-enter { transition: transform .2s ease; }
  .gd-from { transform: translateX(100%); }
  .gd-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 18px 20px; border-bottom: 1px solid var(--border); }
  .gd-head h2 { margin: 0; font-size: 17px; font-weight: 600; }
  .gd-close { background: none; border: 0; color: var(--text-muted); font-size: 16px; cursor: pointer; padding: 4px 6px; border-radius: 6px; }
  .gd-close:hover { color: var(--text); background: var(--surface-2); }
  .gd-body { flex: 1; overflow-y: auto; padding: 18px 20px 32px; font-size: 13.5px; line-height: 1.6; color: var(--text-muted); }
  .gd-intro { margin: 0 0 18px; color: var(--text); }
  .gd-steps { list-style: none; counter-reset: gd; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 14px; }
  .gd-steps li { counter-increment: gd; position: relative; padding-left: 40px; }
  .gd-steps li::before { content: counter(gd, decimal-leading-zero); position: absolute; left: 0; top: 1px; font-family: 'JetBrains Mono', monospace; font-size: 11px; font-weight: 600; color: var(--teal); background: var(--teal-dim); border-radius: 6px; padding: 2px 6px; }
  .gd-steps b { color: var(--text); font-size: 14px; }
  .gd-steps p { margin: 3px 0 0; }
  .gd-body a { color: var(--teal); }
  .gd-body code { font-family: 'JetBrains Mono', monospace; font-size: 12px; background: var(--surface-2); padding: 1px 5px; border-radius: 4px; }
  .gd-tips { margin-top: 22px; padding: 14px 16px; border: 1px solid var(--border); border-radius: var(--radius-sm); background: var(--surface-2); }
  .gd-tips > b { color: var(--text); font-size: 13px; }
  .gd-tips ul { margin: 6px 0 0; padding-left: 18px; display: flex; flex-direction: column; gap: 6px; }
</style>
@endonce
