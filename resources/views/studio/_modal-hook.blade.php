<div class="modal-bg" x-show="editHookOpen" @click="editHookOpen = false">
  <div class="modal" @click.stop>
    <h3 class="modal-title">Edit hook</h3>
    <p class="modal-sub">Clip akan dirender ulang dengan teks hook baru. Kosongkan untuk kembali ke hook bawaan.</p>
    <textarea class="field" maxlength="100" x-model="draftHook" x-ref="hookInput"
              x-effect="editHookOpen && $nextTick(() => $refs.hookInput.focus())"></textarea>
    <div class="field-count" x-text="draftHook.length + '/100'"></div>
    <div class="modal-actions">
      <button class="btn btn-secondary" type="button" @click="editHookOpen = false">Batal</button>
      <button class="btn" type="button" @click="saveHookAndRerender()">Simpan &amp; render ulang</button>
    </div>
  </div>
</div>
