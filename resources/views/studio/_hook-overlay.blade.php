{{-- Hook as it will appear in the video. $text = Alpine expression for the hook text. --}}
<div x-show="pvInHook && hookEnabled" class="pv-hook">
  <div class="pv-hook-block" :class="'hk-' + hookBg" :style="pvHookBlockStyle({{ $text }})">
    <template x-for="(line, i) in pvHook({{ $text }}).lines" :key="i">
      <span class="pv-hook-line"><template x-for="(run, j) in line" :key="j"><span><span x-show="j > 0"> </span><span :class="{ 'hk-em': run.em }" x-text="run.t"></span></span></template></span>
    </template>
  </div>
</div>
