{{-- Fonts and theme for every page. $fonts: extra Google Fonts families (subtitle previews). --}}
<link rel="preconnect" href="https://fonts.googleapis.com" />
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
<link href="https://fonts.googleapis.com/css2?family=Geist:wght@400;500;600;700;800&family=Geist+Mono:wght@400;500;600{{ isset($fonts) ? '&'.$fonts : '' }}&display=swap" rel="stylesheet" />
{{-- Before first paint: an explicit choice wins, otherwise CSS follows the system --}}
<script>
(function () {
  var t = null;
  try { t = localStorage.getItem('clipper_theme'); } catch (e) {}
  if (t !== 'light' && t !== 'dark') {
    t = window.matchMedia && matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark';
  }
  document.documentElement.dataset.theme = t;
  window.toggleTheme = function () {
    var next = document.documentElement.dataset.theme === 'light' ? 'dark' : 'light';
    document.documentElement.dataset.theme = next;
    try { localStorage.setItem('clipper_theme', next); } catch (e) {}
  };
})();
</script>
