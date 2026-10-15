{{-- link https://www.youtube.com/shorts/HyacqXqD5Zk --}}

<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>Clipper Studio — YouTube → TikTok</title>
@include('partials.favicon')
<meta name="csrf-token" content="{{ csrf_token() }}" />
<link rel="preconnect" href="https://fonts.googleapis.com" />
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&family=Roboto:wght@400;700;900&family=Montserrat:wght@600;700;800&family=Oswald:wght@500;700&family=Bebas+Neue&display=swap" rel="stylesheet" />
@vite('resources/css/studio.css')
@include('partials.session-guard')
<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
</head>
<body>
<div class="bg-grain"></div>
<div class="studio-shell" x-data="studio()" x-init="init()" x-cloak>

  @include('studio._topbar')
  @include('studio._wizbar')

  @include('studio.step-1-link')
  @include('studio.step-2-layout')
  @include('studio.step-3-clips')
  @include('studio.step-4-subtitle')
  @include('studio.step-5-hook')
  @include('studio.step-6-generate')
  @include('studio.step-7-pick')

  @include('studio.result')

  @include('studio._footer-nav')
  @include('studio._modal-hook')
  @include('studio._modal-trim')
  @include('studio._history-panel')

</div>

@include('partials.clip-preview-js')
@include('studio._script')
</body>
</html>
