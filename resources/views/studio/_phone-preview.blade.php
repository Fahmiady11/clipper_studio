{{-- Reusable phone preview widget. Requires Alpine context from studio(). --}}
<div class="phone-frame">
  <div class="phone-notch"></div>
  <div x-html="layoutBg(layout)"></div>
  {{-- channel chip --}}
  <div x-show="!(topTitle && !pvInHook)" style="position:absolute;top:52px;left:12px;z-index:5;display:inline-flex;align-items:center;gap:6px;background:rgba(0,0,0,.5);backdrop-filter:blur(6px);padding:4px 10px 4px 4px;border-radius:999px;font-size:9px;font-weight:600;color:#fff">
    <span :style="'width:13px;height:13px;border-radius:50%;background:'+currentLayout.accent"></span>
    <span x-text="sampleMeta.channel"></span>
  </div>
  {{-- karaoke subtitle --}}
  <div x-show="subtitleEnabled && !pvInHook" :style="pvSubStyle()">
    <div :style="pvSubBoxStyle()">
      <template x-for="w in pvSegWords" :key="w.idx">
        <span :style="'color:'+(w.active ? subtitleHighlight : subtitleTextColor)+';transition:color .08s'" x-text="w.w + ' '"></span>
      </template>
    </div>
  </div>
  {{-- clip progress bar (top edge) and title held at the top, as rendered (FFmpegService) --}}
  <div x-show="progressBar" :style="'position:absolute;left:0;top:0;height:1cqw;z-index:30;width:'+pvProgress+'%;background:'+subtitleHighlight"></div>
  <div x-show="topTitle && !pvInHook" :style="'position:absolute;left:0;right:0;top:13%;z-index:5;text-align:center;transform:translateY(-50%);font-family:\''+subtitleFont+'\',sans-serif'">
    <span style="display:inline-block;background:rgba(0,0,0,.9);color:#fff;font-weight:700;font-size:5cqw;padding:1.3cqw 2.8cqw;border-radius:2.2cqw;line-height:1.15">Contoh judul topik dari AI</span>
  </div>
  {{-- hook overlay --}}
  @include('studio._hook-overlay', ['text' => 'pvHookText'])
  <div x-show="pvInHook && hookEnabled" class="pv-hook-tag" x-text="'HOOK · ' + hookDuration + 's' + (hookAi ? ' · contoh AI' : '')"></div>
  {{-- progress bar --}}
  <div style="position:absolute;left:14px;right:14px;bottom:16px;z-index:5;height:2px;background:rgba(255,255,255,.22);border-radius:999px;overflow:hidden">
    <div :style="'width:'+pvProgress+'%;height:100%;background:#fff'"></div>
  </div>
</div>
