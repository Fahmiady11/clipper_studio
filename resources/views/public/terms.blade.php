@extends('public._layout')
@section('title', 'Terms of Service')

@section('content')
<article class="pb-doc">
  <h1>Terms of Service</h1>
  <p class="pb-updated">Last updated: {{ \App\Http\Controllers\PublicPageController::UPDATED }}</p>

  <p>These terms apply to your use of Clipper Studio (the "Service") at {{ parse_url(config('app.url'), PHP_URL_HOST) }}. By using the Service you agree to them. If you do not agree, do not use the Service.</p>

  <h2>1. The Service</h2>
  <p>Clipper Studio turns long-form videos into short vertical clips with subtitles, an opening hook and optional background music, and lets you send a finished clip to your own TikTok account as a draft. Clip suggestions, subtitles, hooks and captions are generated with the help of AI and may contain mistakes; review them before publishing.</p>

  <h2>2. Accounts</h2>
  <p>Access is by invitation. Accounts are created or approved by the administrator, who may suspend or revoke access at any time. You are responsible for keeping your password secret and for all activity under your account.</p>

  <h2>3. Your content and rights</h2>
  <ul>
    <li>Only use videos, audio and music that you own or are licensed to use and publish, including on TikTok.</li>
    <li>You keep all rights to your content. You give us permission to download, process and store it only as needed to provide the Service to you.</li>
    <li>You are responsible for what you publish, including compliance with TikTok's Terms of Service and Community Guidelines.</li>
  </ul>

  <h2>4. TikTok integration</h2>
  <p>When you connect a TikTok account, you authorize the Service through TikTok to identify your account and to upload clips you choose to your TikTok inbox as drafts. A clip is uploaded only when you start the upload for that clip; the Service does not publish posts on your behalf. You can disconnect your TikTok account in the Service at any time, and you can also revoke access in your TikTok settings. Your use of TikTok is governed by TikTok's own terms.</p>

  <h2>5. Acceptable use</h2>
  <p>Do not use the Service to infringe others' rights, to publish unlawful, misleading or harmful content, to send spam, or to interfere with or attempt to gain unauthorized access to the Service or other accounts.</p>

  <h2>6. Availability and changes</h2>
  <p>The Service is provided "as is" and "as available", without warranties of any kind. We may change, suspend or discontinue any part of it at any time. To the extent permitted by law, we are not liable for indirect or consequential damages, or for loss of content, arising from your use of the Service.</p>

  <h2>7. Termination</h2>
  <p>You may stop using the Service at any time and ask us to delete your account. We may suspend or end your access if you break these terms.</p>

  <h2>8. Changes to these terms</h2>
  <p>We may update these terms. The date at the top shows the latest version; continuing to use the Service after a change means you accept it.</p>

  <h2>9. Contact</h2>
  <p>Questions about these terms: @include('public._contact')</p>
</article>
@endsection
