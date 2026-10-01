<div x-show="historyOpen" class="hist-overlay" @click.self="historyOpen = false" x-transition:enter="fadeIn" x-cloak>
  <div class="hist-panel">

    <div class="hist-header">
      <div>
        <h2>Riwayat</h2>
        <div style="font-size:12px;color:var(--text-dim);margin-top:2px" x-text="history.length + ' item tersimpan'"></div>
      </div>
      <div style="display:flex;gap:8px;align-items:center">
        <button class="hist-clear" type="button" x-show="history.length > 0" @click="clearAllHistory()" :disabled="clearingHistory"
                x-text="clearingHistory ? 'Menghapus…' : 'Hapus semua'"></button>
        <button class="hist-close" type="button" @click="historyOpen = false" aria-label="Tutup riwayat">✕</button>
      </div>
    </div>

    <div class="hist-body">

      <template x-if="history.length === 0">
        <div class="hist-empty">
          <div style="font-size:32px;margin-bottom:12px">📋</div>
          <div>Belum ada riwayat.</div>
          <div style="margin-top:6px;font-size:12px">Project mulai tersimpan setelah step 3.</div>
        </div>
      </template>

      <template x-for="item in history" :key="item.id">
        <div class="hist-item">

          <div class="hist-thumb">
            <template x-if="item.thumbnailUrl">
              <img :src="item.thumbnailUrl" :alt="item.title" loading="lazy" />
            </template>
            <template x-if="!item.thumbnailUrl">
              <div class="hist-thumb-fallback">▶</div>
            </template>
          </div>

          <div class="hist-info">
            <div class="hist-title" x-text="item.title"></div>
            <div class="hist-badges">
              <span class="hist-layout-badge" x-text="item.layout"></span>
              <span class="hs-badge" :class="'hs-' + item.status"
                x-text="({ draft: 'DRAFT', processing: 'DIPROSES', generated: 'GENERATED', rendered: 'RENDERED' })[item.status] || item.status.toUpperCase()">
              </span>
            </div>
            <div class="hist-date" x-text="fmtDate(item.savedAt)"></div>
          </div>

          <div class="hist-actions">
            <button class="hist-btn" @click="restoreFromHistory(item)"
              x-text="item.status === 'rendered' ? 'Lihat Hasil' : item.status === 'generated' ? 'Pilih Clip' : 'Lanjutkan'">
            </button>
            <button class="hist-del" type="button" @click="deleteHistoryItem(item)" title="Hapus" aria-label="Hapus dari riwayat">✕</button>
          </div>

        </div>
      </template>

    </div>
  </div>
</div>
