{{--
  Clip look preview shared by the studio and the autopilot settings: hook
  layout/style (same maths as App\Services\HookRenderer — keep in sync) and the
  subtitle box. Exposes window.ClipPreview.
--}}
<script>
window.ClipPreview = (() => {
  // Hook layout — same maths as App\Services\HookRenderer so the preview
  // wraps and sizes the text exactly like the rendered video. Keep in sync.
  const HOOK = { W: 1080, MAX_W: 900, MAX_LINES: 4, SIZE_MAX: 104, SIZE_MIN: 52, LH: 1.2 };
  const HOOK_FONTS = { // heaviest bundled weight per family, like HookRenderer::FONTS
    Arial:        { css: 'Arial, Helvetica, sans-serif', weight: 700, scale: 1 },
    Roboto:       { css: "'Roboto', sans-serif",         weight: 900, scale: 1 },
    Montserrat:   { css: "'Montserrat', sans-serif",     weight: 800, scale: 1 },
    Oswald:       { css: "'Oswald', sans-serif",         weight: 700, scale: 1.1 },
    'Bebas Neue': { css: "'Bebas Neue', sans-serif",     weight: 400, scale: 1.3 },
  };
  let hookCtx = null;
  const cache = new Map();

  // Words with an "emphasised" flag; *word* or *several words* (HookText::tokens)
  function hookTokens(text) {
    const out = []; let inside = false;
    for (const word of text.split(' ')) {
      const opens = word.startsWith('*'), closes = word.length > 1 && word.endsWith('*');
      const clean = word.replaceAll('*', '');
      if (clean) out.push({ w: clean, em: inside || opens });
      if (opens && !closes) inside = true; else if (closes) inside = false;
    }
    return out;
  }

  function hookLayout(text, family) {
    const f = HOOK_FONTS[family] || HOOK_FONTS.Montserrat;
    text = (text || '').replace(/[^\p{L}\p{M}\p{N}\p{P}\p{Zs}\p{Sc}\p{Sm}]/gu, '').trim().replace(/\s+/g, ' ');
    if (family === 'Bebas Neue') text = text.toUpperCase();

    hookCtx = hookCtx || document.createElement('canvas').getContext('2d');
    const width = (s, px) => { hookCtx.font = `${f.weight} ${px}px ${f.css}`; return hookCtx.measureText(s).width; };
    const tokens = text ? hookTokens(text) : [];
    const words = tokens.map(t => t.w);
    const greedy = (px, maxW) => {
      const lines = []; let line = [];
      for (const w of words) {
        if (line.length && width([...line, w].join(' '), px) > maxW) { lines.push(line); line = [w]; } else line.push(w);
      }
      if (line.length) lines.push(line);
      return lines;
    };
    const wrap = (px) => {
      const n = greedy(px, HOOK.MAX_W).length;
      let lo = 0, hi = HOOK.MAX_W;
      while (hi - lo > 4) { const mid = (lo + hi) >> 1; if (greedy(px, mid).length <= n) hi = mid; else lo = mid; }
      return greedy(px, hi);
    };
    // Lines as runs of same-emphasis words, so a marker spans "60 tahun" as one box
    const toRuns = (lines) => {
      let i = 0;
      return lines.map(line => line.reduce((runs, w) => {
        const em = tokens[i++].em, last = runs[runs.length - 1];
        if (last && last.em === em) last.t += ' ' + w; else runs.push({ t: w, em });
        return runs;
      }, []));
    };
    const max = Math.round(HOOK.SIZE_MAX * f.scale), min = Math.round(HOOK.SIZE_MIN * f.scale);
    for (let px = max; px > min; px -= 2) {
      const lines = wrap(px);
      if (lines.length <= HOOK.MAX_LINES && lines.every(l => width(l.join(' '), px) <= HOOK.MAX_W)) return { px, lines: toRuns(lines), font: f };
    }
    return { px: min, lines: toRuns(wrap(min)), font: f };
  }

  function rgbOf(hex) {
    const h = hex.replace('#', '');
    return [0, 2, 4].map(i => parseInt(h.substr(i, 2), 16));
  }
  const colorDistance = (a, b) => Math.hypot(...rgbOf(a).map((v, i) => v - rgbOf(b)[i]));

  function luminance(hex) {
    const [r, g, b] = rgbOf(hex);
    return (0.2126 * r + 0.7152 * g + 0.0722 * b) / 255;
  }
  const cq = v => (v / 10.8).toFixed(3) + 'cqw';
  const FONT_STACK = { Arial: 'Arial,Helvetica,sans-serif', Roboto: "'Roboto',sans-serif", Montserrat: "'Montserrat',sans-serif", Oswald: "'Oswald',sans-serif", 'Bebas Neue': "'Bebas Neue',sans-serif" };

  return {
    // tick: bump when web fonts finish loading so widths are measured again
    hookLayout(text, family, tick = 0) {
      const key = family + '|' + text + '|' + tick;
      if (!cache.has(key)) {
        if (cache.size > 50) cache.clear();
        cache.set(key, hookLayout(text, family));
      }
      return cache.get(key);
    },

    // Inline style for .pv-hook-block, sized in cqw of a 1080px-wide frame
    hookStyle(text, { family, color, accent, bg, pos, tick = 0 }) {
      const { px, font } = this.hookLayout(text, family, tick);
      accent = accent || '#facc15';
      const dark = luminance(color) < 0.45;
      const ink = c => luminance(c) < 0.45 ? '#ffffff' : '#111111';
      const shadow = `0 ${cq(px * .08)} 0 rgba(0,0,0,.35)`;
      const place = { top: 'top:17cqh;transform:translateX(-50%);', bottom: 'bottom:26cqh;transform:translateX(-50%);' }[pos]
        || 'top:50%;transform:translate(-50%,-50%);';
      let s = `${place}font-family:${font.css};font-weight:${font.weight};font-size:${cq(px)};line-height:${cq(px * HOOK.LH)};`;
      if (bg === 'semi') {
        const box = dark ? 'rgba(255,255,255,.9)' : 'rgba(12,12,14,.66)';
        const em = Math.abs(luminance(accent) - (dark ? 1 : 0.05)) > 0.3 ? accent : color;
        s += `color:${color};--hk-em:${em};background:${box};box-shadow:${shadow};padding:${cq(px * .38)} ${cq(px * .52)};border-radius:${cq(px * .4)};`;
      } else if (bg === 'full') {
        // Marker in the accent colour, or dark when it would vanish into the label
        const marker = colorDistance(accent, color) > 120 ? accent : ink(color);
        s += `color:${ink(color)};--hk-bg:${color};--hk-pad:${cq(px * .34)};--hk-r:${cq(px * .22)};--hk-shadow:${shadow};`
          + `--hk-marker:${marker};--hk-marker-ink:${ink(marker)};--hk-mpad:${cq(px * .12)};--hk-mr:${cq(px * .16)};--hk-mh:${cq(px * (HOOK.LH - .2))};`;
      } else {
        // Outline as a ring of shadows (like HookRenderer's stamped outline);
        // -webkit-text-stroke draws nothing on Google's variable Montserrat
        const r = px * .085, line = dark ? '#fff' : '#000';
        const ring = Array.from({ length: 16 }, (_, i) => {
          const a = i * Math.PI / 8;
          return `${cq(Math.cos(a) * r)} ${cq(Math.sin(a) * r)} 0 ${line}`;
        });
        s += `color:${color};--hk-em:${accent};text-shadow:${ring.join(',')},0 ${cq(px * .07 + r)} 0 rgba(0,0,0,.35);`;
      }
      return s;
    },

    // y (% of frame height) = dragged position, centred like ASS \an5\pos in FFmpegService
    subStyle(pos, y = null) {
      const base = 'position:absolute;left:14px;right:14px;z-index:6;text-align:center;';
      if (y !== null && y !== undefined && y !== '') return base + `top:${y}%;transform:translateY(-50%);`;
      if (pos === 'top')    return base + 'top:14%;';
      if (pos === 'center') return base + 'top:50%;transform:translateY(-50%);';
      return base + 'bottom:13%;';
    },

    /**
     * Drag a subtitle up/down inside its .phone-frame. onMove gets the centre
     * as % of frame height (5–95, snaps to the middle); onEnd when released.
     */
    dragSubtitle(event, onMove, onEnd = () => {}) {
      const el = event.currentTarget, frame = el.closest('.phone-frame');
      if (!frame) return;
      event.preventDefault();
      const rect = frame.getBoundingClientRect();
      const box = el.getBoundingClientRect();
      // Keep the grab point under the finger instead of jumping the centre to it
      const offset = event.clientY - (box.top + box.height / 2);
      const move = (e) => {
        let y = (e.clientY - offset - rect.top) / rect.height * 100;
        y = Math.min(95, Math.max(5, y));
        if (Math.abs(y - 50) < 1.5) y = 50;
        onMove(Math.round(y * 2) / 2);
      };
      const up = () => {
        window.removeEventListener('pointermove', move);
        window.removeEventListener('pointerup', up);
        window.removeEventListener('pointercancel', up);
        onEnd();
      };
      window.addEventListener('pointermove', move);
      window.addEventListener('pointerup', up);
      window.addEventListener('pointercancel', up);
    },

    subBoxStyle({ font, size, bg }) {
      const fill = { none: 'transparent', semi: 'rgba(0,0,0,.6)', full: 'rgba(0,0,0,.9)' }[bg] || 'transparent';
      const bare = bg === 'none';
      return `display:inline-block;background:${fill};padding:${bare ? '0' : '6px 10px'};border-radius:${bg === 'full' ? '0' : '8px'};`
        + `font-family:${FONT_STACK[font] || 'sans-serif'};font-size:${Math.round(size * 0.34)}px;font-weight:800;line-height:1.2;letter-spacing:-.01em;`
        + `text-shadow:${bare ? '0 2px 8px rgba(0,0,0,.85)' : 'none'};text-transform:${font === 'Bebas Neue' ? 'uppercase' : 'none'}`;
    },
  };
})();
</script>
