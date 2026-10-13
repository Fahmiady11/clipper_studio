@extends('public._layout')
@section('title', 'Privacy Policy')

@section('content')
<article class="pb-doc">
  <h1>Privacy Policy</h1>
  <p class="pb-updated">Last updated: {{ \App\Http\Controllers\PublicPageController::UPDATED }}</p>

  <p>This policy explains what information Clipper Studio (the "Service") collects, how it is used and shared, and the choices you have.</p>

  <h2>1. Information we collect</h2>
  <ul>
    <li><strong>Account information</strong>: your name, email address and password (stored only as a one-way hash).</li>
    <li><strong>Content you provide</strong>: links to videos you want to clip, the source videos the Service downloads from those links, transcripts and subtitles made from them, your clip settings, and the clips the Service renders. Music you upload to the music library, if any.</li>
    <li><strong>TikTok account information</strong>, if you connect TikTok: your TikTok open ID, display name and avatar, the permissions you granted, and the access and refresh tokens TikTok issues to the Service. We do not receive your TikTok password.</li>
    <li><strong>Technical information</strong>: a session cookie that keeps you signed in, and server logs (such as IP address and request times) used for security and troubleshooting.</li>
  </ul>

  <h2>2. How we use information</h2>
  <ul>
    <li>To provide the Service: download and transcribe your videos, suggest clips, render them with subtitles, hooks and music, and generate captions and hashtags.</li>
    <li>To upload a clip to your TikTok inbox as a draft when you choose to, and to show which TikTok account is connected.</li>
    <li>To keep the Service secure, prevent abuse and fix problems.</li>
  </ul>
  <p>We do not sell your information and do not use it for advertising.</p>

  <h2>3. Sharing with service providers</h2>
  <p>We share information only as needed to run the Service:</p>
  <ul>
    <li><strong>Google (Gemini API)</strong>: transcripts and clip details are sent to suggest clips and write hooks, captions and hashtags.</li>
    <li><strong>Groq</strong>: audio from a video may be sent to create a transcript when the video has no usable subtitles.</li>
    <li><strong>YouTube</strong>: the Service downloads the videos you link to.</li>
    <li><strong>TikTok</strong>: clips you choose to upload, and the requests needed to identify your connected account.</li>
  </ul>
  <p>We may also disclose information if required by law.</p>

  <h2>4. TikTok data</h2>
  <p>Data received from TikTok is used only to identify the connected account in the Service and to upload the clips you choose. It is not shared with other parties or used for any other purpose. Disconnecting your TikTok account in the Service deletes its stored tokens and profile details from our database; you can also revoke the Service's access in your TikTok settings.</p>

  <h2>5. Retention</h2>
  <ul>
    <li>Downloaded source videos are temporary and deleted automatically after about {{ config('services.clipper.temp_retention_hours') }} hours without use.</li>
    <li>Projects, transcripts and rendered clips are kept until you delete them from your history, or until your account is deleted. Rejected autopilot clips are deleted right away.</li>
    <li>Account information is kept while your account exists.</li>
  </ul>

  <h2>6. Your choices</h2>
  <p>You can delete projects and clips from your history at any time, disconnect your TikTok account, and ask us to access, correct or delete your account and data by contacting us below.</p>

  <h2>7. Security</h2>
  <p>The Service is served over HTTPS, passwords are hashed, and access to each account's projects is restricted to that account. No method of storage or transmission is completely secure, but we work to protect your information.</p>

  <h2>8. Children</h2>
  <p>The Service is not intended for children under 13 (or the minimum age required in your country) and we do not knowingly collect their information.</p>

  <h2>9. Changes</h2>
  <p>We may update this policy. The date at the top shows the latest version.</p>

  <h2>10. Contact</h2>
  <p>Privacy questions and requests: @include('public._contact')</p>
</article>
@endsection
