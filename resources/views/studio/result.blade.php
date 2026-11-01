{{-- Result screen. Order follows what the user does next: check the video, read what it is,
     settle the caption, publish, then optionally test hooks. Lime marks only the send button. --}}
<div class="rs fade-in" x-show="done">

  {{-- Preview: the rendered clip and what can be done to the file itself --}}
  <aside class="rs-aside" aria-label="Pratinjau clip">
    <div class="phone-frame rs-phone">
      <div class="phone-notch"></div>
      <div x-html="layoutBg(layout)"></div>
      <div style="position:absolute;top:52px;left:12px;z-index:5;display:inline-flex;align-items:center;gap:6px;background:rgba(0,0,0,.5);backdrop-filter:blur(6px);padding:4px 10px 4px 4px;border-radius:999px;font-size:9px;font-weight:600;color:#fff">
        <span :style="'width:13px;height:13px;border-radius:50%;background:'+currentLayout.accent"></span>
        <span x-text="sampleMeta.channel"></span>
      </div>
      <div x-show="subtitleEnabled && !pvInHook" :style="pvSubStyle()">
        <div :style="pvSubBoxStyle()">
          <template x-for="w in pvSegWords" :key="w.idx">
            <span :style="'color:'+(w.active ? subtitleHighlight : subtitleTextColor)+';transition:color .08s'" x-text="w.w + ' '"></span>
          </template>
        </div>
      </div>
      @include('studio._hook-overlay', ['text' => 'resultHookText'])
      <div style="position:absolute;left:14px;right:14px;bottom:16px;z-index:5;height:2px;background:rgba(255,255,255,.22);border-radius:999px;overflow:hidden">
        <div :style="'width:'+pvProgress+'%;height:100%;background:#fff'"></div>
      </div>
    </div>
    <div class="rs-spec" x-text="'1080×1920 · H.264 · ' + (selectedClipObj ? Math.round(selectedClipObj.end_seconds - selectedClipObj.start_seconds) : 0) + ' dtk · ' + currentLayout.title"></div>

    <div class="rs-file-acts">
      <a :href="downloadUrl || '#'" :download="downloadUrl ? 'clip.mp4' : null" class="btn btn-secondary"
         :aria-disabled="!downloadUrl" :style="!downloadUrl ? 'opacity:.5;pointer-events:none' : ''">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M12 3v14m0 0l-5-5m5 5l5-5M5 21h14"/></svg>
        Download MP4
      </a>
      <button class="btn btn-ghost" type="button" x-show="hookEnabled && !hookTest" @click="openEditHook()">Edit hook &amp; render ulang</button>
    </div>
  </aside>

  <div class="rs-main">

    {{-- What this clip is --}}
    <header class="rs-head">
      <div class="rs-eyebrow">Selesai dirender</div>
      <h1 class="rs-title" x-text="selectedClipObj ? selectedClipObj.topic : ''"></h1>
      <p class="rs-meta">
        <span x-text="selectedClipObj ? 'Clip #' + selectedClipObj.ranking : ''"></span>
        <span x-show="selectedClipObj?.viral_potential" x-text="'Potensi viral ' + (selectedClipObj?.viral_potential || '')"></span>
        <span x-show="meta?.title" x-text="meta?.title"></span>
      </p>
      <div class="music-warn" x-show="musicMissing" style="margin-top:14px">
        Musik latar aktif, tapi library musik kosong saat dirender, jadi clip ini <b>tanpa musik</b>.
        <a href="/music">Upload lagu</a>, lalu render ulang lewat "Edit hook &amp; render ulang".
      </div>
    </header>

    {{-- Caption: one text for every platform; the panel below says where a platform cuts it --}}
    <section class="rs-card" aria-labelledby="rs-cap-title">
      <div class="rs-card-head">
        <h2 id="rs-cap-title">Caption &amp; hashtag</h2>
        <button class="btn btn-ghost btn-sm" type="button" x-show="!loadingCaption && pub.caption"
                @click="copyText('cap', pub.caption + '\n\n' + pubTags.join(' '))" x-text="copied === 'cap' ? 'Tersalin' : 'Salin semua'"></button>
      </div>
      <p class="rs-muted" x-show="loadingCaption">Membuat caption dengan AI…</p>
      <p class="rs-muted" x-show="!loadingCaption && !clipCaption && !pub.caption">
        Caption belum tersedia. <button class="rs-link" type="button" @click="fetchCaption()">Coba buat lagi</button>, atau tulis sendiri di bawah.
      </p>
      <div class="rs-fields" x-show="!loadingCaption">
        <label class="rs-field">
          <span class="rs-label">Caption</span>
          <textarea class="field" rows="4" maxlength="5000" x-model="pub.caption"></textarea>
        </label>
        <label class="rs-field">
          <span class="rs-label">Hashtag <span class="rs-dim">pisahkan dengan spasi</span></span>
          <input class="field" x-model="pub.hashtags" placeholder="#bisnis #umkm" style="font-family:var(--font-mono)">
        </label>
      </div>
    </section>

    {{-- Publish: every ticked platform gets the clip on its default account, now or at a time --}}
    <section class="rs-card rs-pub" aria-labelledby="rs-pub-title">
      <div class="rs-card-head">
        <h2 id="rs-pub-title">Publikasikan</h2>
        <a class="rs-link" href="/settings/accounts">Akun terhubung</a>
      </div>
      <p class="rs-muted" x-show="pub.loading && !pub.platforms.length">Memuat platform…</p>
      <p class="rs-error" x-show="pub.loadError" role="alert">
        <span x-text="pub.loadError"></span> <button class="rs-link" type="button" @click="loadPublishPanel()">Coba lagi</button>
      </p>

      <ul class="rs-plats">
        <template x-for="p in pub.platforms" :key="p.id">
          <li class="rs-plat" :class="{ on: pubChecked(p.id), off: !!pubBlocked(p), err: !!pub.errors['platforms.' + p.id] }">
            <label class="rs-plat-row">
              <input type="checkbox" :checked="pubChecked(p.id)" :disabled="!!pubBlocked(p)" @change="pubToggle(p)">
              <span class="rs-plat-name" x-text="PUB_NAMES[p.id]"></span>
              <span class="rs-plat-acct" x-show="p.account && !pubBlocked(p)" x-text="p.account?.name"></span>
            </label>
            <a class="rs-link rs-plat-side" :href="p.settings_url" x-show="p.configured && (!p.refusal || !p.account)" x-text="p.account ? 'Ganti' : (p.needs_default ? 'Pilih akun' : 'Hubungkan')"></a>
            <p class="rs-plat-why" x-show="pubBlocked(p)" x-text="pubBlocked(p)"></p>

            {{-- Only what this platform needs beyond the shared caption --}}
            <div class="rs-plat-more" x-show="pubChecked(p.id)">
              <p class="rs-dim" x-show="pubAdjust(p.id)" x-text="pubAdjust(p.id)"></p>

              <template x-if="p.id === 'youtube'">
                <div class="rs-fields">
                  <label class="rs-field">
                    <span class="rs-label">Judul <span class="rs-dim" x-text="pub.ytTitle.length + '/100'"></span></span>
                    <input class="field" maxlength="100" x-model="pub.ytTitle">
                  </label>
                  <p class="rs-error" x-show="/[<>]/.test(pub.ytTitle)">Judul YouTube tidak boleh berisi &lt; atau &gt;.</p>
                  <div>
                    <span class="rs-label" id="rs-yt-priv">Siapa yang bisa melihat</span>
                    <div class="seg" role="group" aria-labelledby="rs-yt-priv">
                      <button type="button" :class="{ on: pub.ytPrivacy === 'public' }" @click="pub.ytPrivacy = 'public'">Publik</button>
                      <button type="button" :class="{ on: pub.ytPrivacy === 'unlisted' }" @click="pub.ytPrivacy = 'unlisted'">Tidak publik</button>
                      <button type="button" :class="{ on: pub.ytPrivacy === 'private' }" @click="pub.ytPrivacy = 'private'">Pribadi</button>
                    </div>
                  </div>
                  <p class="rs-dim">Selama app belum lolos audit YouTube API, video tersimpan sebagai Pribadi; ubah ke Publik di YouTube Studio.</p>
                </div>
              </template>

              <template x-if="p.id === 'tiktok'">
                <div class="rs-fields">
                  <div class="seg" role="group" aria-label="Cara kirim ke TikTok">
                    <button type="button" :class="{ on: tiktokMode === 'inbox' }" @click="tiktokMode = 'inbox'">Draft (inbox)</button>
                    <button type="button" :class="{ on: tiktokMode === 'direct' }" @click="tiktokMode = 'direct'">Posting langsung</button>
                  </div>
                  {{-- TikTok's rules for a direct post: creator name, privacy, interaction toggles, disclosure, consent --}}
                  <div x-show="tiktokMode === 'direct'" class="rs-fields">
                    <p class="rs-dim" x-show="tiktokCreatorLoading">Membaca info akun TikTok…</p>
                    <p class="rs-error" x-show="tiktokCreatorError" x-text="tiktokCreatorError"></p>
                    <template x-if="tiktokCreator">
                      <div class="rs-fields">
                        <p class="rs-muted">Posting sebagai <b x-text="tiktokCreator.creator_nickname || tiktokCreator.creator_username"></b>, hanya terlihat oleh kamu (Hanya saya) sampai app TikTok lolos audit.</p>
                        <p class="rs-error" x-show="tiktokTooLong" x-text="`Clip ini ${Math.round(tiktokClipSeconds)} detik, akun TikTok ini maksimal ${tiktokCreator.max_video_post_duration_sec} detik.`"></p>
                        <div class="rs-checks">
                          <label :class="{ off: tiktokCreator.comment_disabled }"><input type="checkbox" x-model="tiktokAllowComment" :disabled="tiktokCreator.comment_disabled"> Izinkan komentar</label>
                          <label :class="{ off: tiktokCreator.duet_disabled }"><input type="checkbox" x-model="tiktokAllowDuet" :disabled="tiktokCreator.duet_disabled"> Izinkan duet</label>
                          <label :class="{ off: tiktokCreator.stitch_disabled }"><input type="checkbox" x-model="tiktokAllowStitch" :disabled="tiktokCreator.stitch_disabled"> Izinkan stitch</label>
                        </div>
                        <div class="rs-box">
                          <label class="rs-check-row">
                            <input type="checkbox" x-model="tiktokDisclose" @change="if (!tiktokDisclose) { tiktokBrandOrganic = false; tiktokBrandContent = false }">
                            <span><b>Ungkapkan konten video</b><span class="rs-dim">Aktifkan kalau video ini mempromosikan brand, produk, atau jasa.</span></span>
                          </label>
                          <div x-show="tiktokDisclose" class="rs-fields" style="margin:10px 0 0 30px">
                            <label class="rs-check-row"><input type="checkbox" x-model="tiktokBrandOrganic"><span>Brand kamu sendiri<span class="rs-dim">Kamu mempromosikan diri sendiri atau bisnismu.</span></span></label>
                            <label class="rs-check-row"><input type="checkbox" x-model="tiktokBrandContent"><span>Branded content<span class="rs-dim">Kamu mempromosikan brand lain atau pihak ketiga.</span></span></label>
                            <p class="rs-warn" x-show="!tiktokBrandOrganic && !tiktokBrandContent">Pilih minimal satu jenis promosi.</p>
                            <p class="rs-dim" x-show="tiktokBrandLabel" x-text="`Video kamu akan diberi label '${tiktokBrandLabel}'. Konten branded belum bisa diposting selama app TikTok menunggu audit.`"></p>
                          </div>
                        </div>
                        <p class="rs-dim">
                          Dengan memposting, kamu menyetujui
                          <template x-if="tiktokBrandContent"><span><a class="rs-link" href="https://www.tiktok.com/legal/page/global/bc-policy/en" target="_blank" rel="noopener">Branded Content Policy</a> dan </span></template>
                          <a class="rs-link" href="https://www.tiktok.com/legal/page/global/music-usage-confirmation/en" target="_blank" rel="noopener">Music Usage Confirmation</a> TikTok.
                        </p>
                      </div>
                    </template>
                  </div>
                </div>
              </template>
            </div>

            <p class="rs-error" x-show="pub.errors['platforms.' + p.id]" x-text="(pub.errors['platforms.' + p.id] || [])[0]" role="alert"></p>

            {{-- After sending: where this platform is --}}
            <div class="rs-plat-state" x-show="p.state" :class="pubStateTone(p.state)">
              <span class="rs-plat-state-label" x-text="pubStateLabel(p)"></span>
              <span class="rs-dim" x-show="p.state?.error" x-text="p.state?.error"></span>
              <span class="rs-dim" x-show="p.state?.note" x-text="p.state?.note"></span>
              <span class="rs-plat-links">
                <a class="rs-link" x-show="p.state?.url" :href="p.state?.url" target="_blank" rel="noopener" x-text="'Buka di ' + p.name"></a>
                <a class="rs-link" x-show="p.state?.reconnect_url" :href="p.state?.reconnect_url">Hubungkan ulang</a>
                <a class="rs-link" x-show="['scheduled', 'failed'].includes(p.state?.status)" href="/calendar">Atur di kalender</a>
              </span>
            </div>
          </li>
        </template>
      </ul>

      <div class="rs-send" x-show="pub.platforms.length">
        <div class="seg" role="group" aria-label="Kapan dikirim">
          <button type="button" :class="{ on: pub.when === 'now' }" @click="pub.when = 'now'">Sekarang</button>
          <button type="button" :class="{ on: pub.when === 'later' }"
                  @click="pub.when = 'later'; if (!pub.at) { const d = new Date(Date.now() + 3600000); d.setMinutes(0, 0, 0); pub.at = new Date(d - d.getTimezoneOffset() * 60000).toISOString().slice(0, 16); }">Jadwalkan</button>
        </div>
        <label class="rs-when" x-show="pub.when === 'later'">
          <span class="rs-label">Tanggal dan jam</span>
          <input class="field" type="datetime-local" x-model="pub.at" style="font-family:var(--font-mono)">
        </label>
        <button class="btn rs-send-btn" type="button" @click="sendPublish()" :disabled="!pubCanSend" x-text="pubSendLabel()"></button>
      </div>
      <p class="rs-dim" x-show="pub.when === 'later'">Jadwal masuk ke <a class="rs-link" href="/calendar">kalender</a>; worker mengirimnya saat waktunya tiba.</p>
      <p class="rs-error" x-show="pub.error" x-text="pub.error" role="alert"></p>
    </section>

    {{-- Tes hook A/B: hook asli lawan 2-3 hook baru, pemenang dari views TikTok --}}
    <section class="rs-card" x-show="selectedClipObj" aria-labelledby="ht-title">
      <div class="rs-card-head">
        <h2 id="ht-title">Tes hook A/B</h2>
        <button class="btn btn-ghost btn-sm" type="button" x-show="hookTest && hookTest.status !== 'testing'" :disabled="hookTestBusy" @click="deleteHookTest()">Hapus tes</button>
      </div>

      {{-- Belum ada tes --}}
      <div x-show="!hookTest && !hookTestLoading">
        <p class="ht-lead">Hook baru diadu dengan hook yang sekarang. Semua versi diposting ke satu akun TikTok di jam yang sama, satu per hari. Pemenangnya dipilih dari views di umur posting yang sama.</p>
        <div class="seg" role="group" aria-label="Jumlah hook baru" style="margin:14px 0 10px">
          <button type="button" :class="{ on: hookTestCount === 2 }" :aria-pressed="hookTestCount === 2" @click="hookTestCount = 2">2 hook baru</button>
          <button type="button" :class="{ on: hookTestCount === 3 }" :aria-pressed="hookTestCount === 3" @click="hookTestCount = 3">3 hook baru</button>
        </div>
        <p class="ht-note" x-text="hookTestCostText"></p>
        <button class="btn btn-secondary" type="button" @click="createHookTest()" :disabled="hookTestBusy" :style="hookTestBusy ? 'opacity:.5;pointer-events:none' : ''"
                x-text="hookTestBusy ? 'Menulis hook…' : 'Buat varian'"></button>
      </div>
      <div x-show="hookTestLoading && !hookTest" class="ht-note">Memuat tes hook…</div>
      <div x-show="hookTestError" class="ht-error" role="alert" x-text="hookTestError"></div>

      {{-- Tes berjalan atau selesai --}}
      <template x-if="hookTest">
        <div>
          <div class="ht-verdict" x-show="hookTest.status === 'decided'" role="status">
            <div class="ht-verdict-title" x-text="hookTestWinner && hookTestWinner.is_control ? 'Hook yang sekarang menang' : 'Pemenang: gaya ' + (hookTestWinner ? hookTestWinner.angle_label : '')"></div>
            <div class="ht-verdict-hook" x-text="hookTestWinner ? plainHook(hookTestWinner.hook_text) : ''"></div>
            <div class="ht-note" x-text="hookTestWinner && hookTestWinner.is_control ? 'Hook baru kalah dari hook aslinya, jadi tidak perlu diganti.' : 'Hook ini dicatat sebagai gaya yang berhasil dan dipakai untuk menulis hook berikutnya.'"></div>
          </div>
          <div class="ht-verdict" x-show="hookTest.status === 'inconclusive'" role="status">
            <div class="ht-verdict-title">Belum ada pemenang</div>
            <div class="ht-note" x-text="hookTest.note"></div>
            <div class="ht-note">Hapus tes ini untuk mencoba lagi dengan hook lain.</div>
          </div>

          <ul class="ht-list" aria-live="polite">
            <template x-for="arm in hookTest.variants" :key="arm.id">
              <li class="ht-arm" :class="{ 'is-winner': arm.is_winner }">
                <div class="ht-arm-main">
                  <div class="ht-arm-tag">
                    <span x-text="arm.is_control ? 'Hook asli' : 'Gaya ' + arm.angle_label"></span>
                    <span x-show="arm.is_winner" class="ht-win">Pemenang</span>
                  </div>
                  <div class="ht-arm-hook" x-text="plainHook(arm.hook_text) || '(tanpa teks)'"></div>
                  <div class="ht-arm-state" :class="{ 'is-fail': arm.status === 'failed' || arm.post_status === 'failed' || arm.delivery === 'failed', 'is-warn': arm.status === 'done' && arm.post_status === 'queued' && arm.delivery === 'unknown' }" x-text="hookArmState(arm)"></div>
                </div>
                <div class="ht-arm-side">
                  <div class="ht-arm-num" x-text="hookArmStats(arm)"></div>
                  <a class="rs-link" x-show="arm.download_url && arm.status === 'done'" :href="arm.download_url + '?inline=1'" target="_blank" rel="noopener" :aria-label="'Lihat video: ' + plainHook(arm.hook_text)">Lihat video</a>
                  <button class="rs-link" type="button" x-show="arm.status === 'failed' || (arm.status === 'pending' && !arm.is_control)" @click="renderHookArm(arm)">Render ulang</button>
                </div>
              </li>
            </template>
          </ul>

          {{-- Semua versi sudah jadi: jadwalkan ke akun TikTok utama --}}
          <div x-show="hookTest.status === 'draft' && hookTestAllDone" style="margin-top:16px">
            <div x-show="!tiktokAccounts.length">
              <p class="ht-note">Hubungkan akun TikTok dulu untuk memposting dan membaca views-nya. <a class="rs-link" href="/settings/accounts#tiktok">Buka Akun terhubung</a>.</p>
            </div>
            <div x-show="tiktokAccounts.length">
              <p class="ht-note" x-show="tiktokAccountId">Semua versi diposting ke <b x-text="accountLabel(tiktokAccounts, tiktokAccountId)"></b>, akun TikTok utama. <a class="rs-link" href="/settings/accounts#tiktok">Ganti</a></p>
              <p class="ht-note rs-warn" x-show="!tiktokAccountId">Akun utama TikTok sudah diputus. <a class="rs-link" href="/settings/accounts#tiktok">Pilih akun utama</a> dulu.</p>
              <span class="rs-label" id="ht-gap">Jarak antar posting</span>
              <div class="seg" role="group" aria-labelledby="ht-gap" style="margin-bottom:10px">
                <template x-for="h in [12, 24, 48]" :key="h">
                  <button type="button" :class="{ on: hookTestGap === h }" :aria-pressed="hookTestGap === h" @click="hookTestGap = h" x-text="h + ' jam'"></button>
                </template>
              </div>
              <p class="ht-note">Butuh izin <code>video.publish</code> dan <code>video.list</code> di akun TikTok. Selama app TikTok belum lolos audit, posting bersifat privat dan views bisa terlalu sedikit untuk dibandingkan.</p>
              <button class="btn btn-secondary" type="button" @click="publishHookTest()" :disabled="hookTestBusy || !tiktokAccountId" :style="hookTestBusy || !tiktokAccountId ? 'opacity:.5;pointer-events:none' : ''"
                      x-text="hookTestBusy ? 'Menjadwalkan…' : 'Jadwalkan tes ke TikTok'"></button>
            </div>
          </div>
          <p class="ht-note" x-show="hookTest.status === 'draft' && !hookTestAllDone" style="margin-top:14px">Menunggu semua versi selesai dirender.</p>
          <p class="ht-note" x-show="hookTest.status === 'testing'" style="margin-top:14px" x-text="hookTestTestingText"></p>
        </div>
      </template>
    </section>

    {{-- Leaving this clip --}}
    <nav class="rs-foot" aria-label="Lanjut">
      <button class="btn btn-ghost" type="button" @click="done = false; step = 7">Pilih clip lain</button>
      <button class="btn btn-ghost" type="button" @click="restart()">Buat project baru</button>
    </nav>
  </div>
</div>
