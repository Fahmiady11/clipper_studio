@extends('public._layout')
@section('title', 'Privacy Policy')

@section('content')
<article class="pb-doc">
  <h1>Privacy Policy</h1>
  <p class="pb-updated">Last updated: {{ \App\Http\Controllers\PublicPageController::UPDATED }}</p>

  <p>This policy explains what information Clipper Studio (the "Service") collects, how it is used and shared, how long it is kept, and the choices you have. The Service lets you turn long YouTube videos into short vertical clips and publish them to your own TikTok, YouTube, Facebook, Instagram and Threads accounts.</p>

  <h2>1. Information we collect</h2>
  <ul>
    <li><strong>Account information</strong>: your name, email address and password (stored only as a one-way hash). If you create API tokens, only a hash of each token is stored.</li>
    <li><strong>Content you provide</strong>: links to videos you want to clip, the source videos the Service downloads from those links, transcripts and subtitles made from them, your clip settings and brand kit (including a logo you upload), music and gameplay footage you upload, the channels or keywords you ask Autopilot to watch, and the clips the Service renders with their captions, hashtags and schedules.</li>
    <li><strong>TikTok account information</strong>, if you connect TikTok: your TikTok open ID, display name and avatar, the permissions you granted, and the access and refresh tokens TikTok issues to the Service. We do not receive your TikTok password.</li>
    <li><strong>YouTube channel information</strong>, if you connect YouTube: your channel ID, channel name and thumbnail, the permissions you granted, and the access and refresh tokens Google issues to the Service. We do not receive your Google password.</li>
    <li><strong>Facebook and Instagram information</strong>, if you connect Facebook: your Facebook user ID and name, the permissions you granted, a long-lived access token, and, for each Page and Instagram professional account you choose to connect, its ID, name, username, profile picture link and (for a Page) its own access token. We do not receive your Facebook or Instagram password.</li>
    <li><strong>Threads information</strong>, if you connect Threads: your Threads user ID, username and profile picture link, the permissions you granted, and the access token Threads issues to the Service.</li>
    <li><strong>Publishing records</strong>: for each clip you publish, the platform's ID and link for the post, its status or error, and when it was sent. For clips posted to TikTok, the view, like, comment and share counts read back from TikTok (see section 5).</li>
    <li><strong>Usage records</strong>: the length and processing cost of each render, counted against your monthly render quota.</li>
    <li><strong>Technical information</strong>: a session cookie that keeps you signed in, and server logs (such as IP address and request times) used for security and troubleshooting.</li>
  </ul>
  <p>Access tokens and refresh tokens for TikTok, YouTube, Facebook, Instagram and Threads are encrypted in our database.</p>

  <h2>2. How we use information</h2>
  <ul>
    <li>To provide the Service: download and transcribe your videos, suggest clips, render them with subtitles, hooks, music and effects, and generate captions and hashtags.</li>
    <li>To upload a clip to your TikTok inbox as a draft, or post it to your profile with the settings you choose, and to show which TikTok account is connected.</li>
    <li>To upload a clip to your YouTube channel as a Short with the title, description and visibility you set, to show which channel is connected, and to check whether an upload you made has finished processing.</li>
    <li>To publish a clip as a Reel to a Facebook Page or Instagram professional account you connected, or as a video to your Threads profile, and to check that the post went through.</li>
    <li>To send a clip only when you ask for it: when you start the upload, when you schedule it in the calendar, when you approve it in the Autopilot review queue for a platform where you switched Autopilot on, or when an API token you created with the publish ability calls the Service.</li>
    <li>To compare hook versions in an A/B test and to learn which kinds of clips do well for you, using the performance numbers of your own clips.</li>
    <li>To keep the Service secure, prevent abuse and fix problems.</li>
  </ul>
  <p>We do not sell your information and do not use it for advertising.</p>

  <h2>3. Sharing with service providers</h2>
  <p>We share information only as needed to run the Service:</p>
  <ul>
    <li><strong>Google (Gemini API)</strong>: transcripts and clip details are sent to suggest clips and write hooks, captions and hashtags. Totals of how your own earlier clips performed (views and similar counts, with the hook styles used) may be included to improve those suggestions. When an AI gateway is configured, the same requests go to that gateway first.</li>
    <li><strong>Groq</strong>: audio from a video may be sent to create a transcript when the video has no usable subtitles.</li>
    <li><strong>Pexels and Pixabay</strong>: short search keywords taken from a clip's topic, to find stock B-roll. Your videos are not sent to them.</li>
    <li><strong>YouTube</strong>: the Service downloads the videos you link to.</li>
    <li><strong>TikTok</strong>: clips you choose to upload, the requests needed to identify your connected account, and the requests that read the counts of clips you posted.</li>
    <li><strong>YouTube (Google)</strong>: clips you choose to upload with their title and description, and the requests needed to identify your connected channel and check the status of your uploads.</li>
    <li><strong>Meta (Facebook, Instagram, Threads)</strong>: clips you choose to publish, and the requests needed to list your Pages, identify the accounts you connect and check the status of your posts. Instagram and Threads download the video from our server: we give them a signed link to that one clip, which stops working after about {{ config('services.clipper.public_media_minutes') }} minutes, and Meta fetches the file through it. Facebook receives the file as an upload from us.</li>
  </ul>
  <p>We may also disclose information if required by law.</p>

  <h2>4. TikTok, YouTube and Meta data</h2>
  <p>Data received from TikTok, YouTube and Meta is used only to identify the connected account in the Service, to publish the clips you choose, to follow the status of those posts, and, for TikTok, to read the counts described in section 5. It is not sold, not used for advertising and not shared with other parties except the service providers in section 3.</p>

  <h3>TikTok</h3>
  <p>The Service asks for the Login Kit and Content Posting API permissions (<code>user.info.basic</code>, <code>video.upload</code>, <code>video.publish</code>) and, to read statistics, <code>video.list</code>. Disconnecting your TikTok account in the Service deletes its stored tokens and profile details from our database. You can also remove the Service in the TikTok app, under your account settings for connected apps and services.</p>

  <h3>YouTube and Google user data</h3>
  <p>The Service uses YouTube API Services, with the scopes <code>youtube.upload</code> (to upload your clips) and <code>youtube.readonly</code> (to read your channel name and picture, and the processing status of videos the Service uploaded). Clipper Studio's use and transfer to any other app of information received from Google APIs will adhere to the <a href="https://developers.google.com/terms/api-services-user-data-policy" target="_blank" rel="noopener">Google API Services User Data Policy</a>, including the Limited Use requirements. That means we use data from Google APIs only to provide and improve the user-facing features described here, we do not transfer it to others except as needed to provide those features, to comply with law, or as part of a merger or sale with your notice, we do not use it for advertising, and no person reads it except with your consent, for security or abuse investigations, or to comply with law.</p>
  <p>Disconnecting the channel in the Service deletes its stored tokens and channel details from our database. You can revoke the Service's access at any time on the <a href="https://security.google.com/settings/security/permissions" target="_blank" rel="noopener">Google security settings page</a>. See also the <a href="https://www.youtube.com/t/terms" target="_blank" rel="noopener">YouTube Terms of Service</a> and the <a href="https://policies.google.com/privacy" target="_blank" rel="noopener">Google Privacy Policy</a>.</p>

  <h3>Facebook, Instagram and Threads</h3>
  <p>Facebook Login for Business gives the Service these permissions: <code>pages_show_list</code> and <code>pages_read_engagement</code> (list the Pages you manage and read the basic details of the Page you pick), <code>pages_manage_posts</code> (publish a Reel to a Page you chose), and <code>instagram_basic</code> and <code>instagram_content_publish</code> (read the basic details of, and publish a Reel to, the Instagram professional account linked to a Page you chose). Threads login gives <code>threads_basic</code> (your username and profile picture) and <code>threads_content_publish</code> (publish a video to your profile). The Service reads the list of Pages you can manage only so you can choose; it keeps the Pages and Instagram accounts you chose and nothing about the others. Disconnecting a Page, an Instagram account or a Threads profile in the Service deletes its stored tokens and details from our database, and removing a Facebook login deletes the login with every Page and Instagram account under it.</p>
  <p>The same happens without signing in to the Service. When you remove Clipper Studio from your Facebook or Threads settings, or send a data deletion request for it from Facebook or Threads, Meta notifies the Service and it deletes that login right away, with its token, your user ID and name, and every Page, Instagram account or Threads profile connected through it. A deletion request answers with a confirmation code and a link to a page showing the status of that request. Your Clipper Studio account itself, which signs in with email and password, is not deleted by these requests; see section 7 to delete it.</p>
  <p>Disconnecting in the Service does not remove the Service from the list of apps on the other platform. To do that, remove Clipper Studio from your Facebook <a href="https://www.facebook.com/settings?tab=business_tools" target="_blank" rel="noopener">Business Integrations</a>, from the Instagram <a href="https://www.instagram.com/accounts/manage_access/" target="_blank" rel="noopener">Apps and Websites</a> settings, and, for Threads, from the connected apps in your Threads or Instagram account settings.</p>

  <h2>5. Statistics we read from TikTok</h2>
  <p>With the TikTok <code>video.list</code> permission, the Service looks up the view, like, comment and share counts of clips that were posted through the Service, and only those. It stores a snapshot of those counts with the clip. They are used to pick the winner of a hook A/B test, to show how your clips perform and to guide the clip and hook suggestions for your account. The Service does not read your other TikTok videos or your followers.</p>

  <h2>6. Retention and deletion</h2>
  <ul>
    <li>Downloaded source videos are temporary and deleted automatically after about {{ config('services.clipper.temp_retention_hours') }} hours without use.</li>
    <li>Projects, transcripts, rendered clips, publishing records and statistics are kept until you delete them from your history, or until your account is deleted. Rejected Autopilot clips are deleted right away.</li>
    <li>Platform tokens are kept until you disconnect the account, until the platform stops accepting them, or until your account is deleted.</li>
    <li>Account information is kept while your account exists.</li>
    <li>When an account is deleted, its database records are removed at once, including connected-account details and tokens, projects, transcripts, schedules, statistics, API tokens and usage records, and its rendered clips, brand logo and uploaded gameplay footage are deleted from storage. Scheduled posts and Autopilot stop before anything else, so nothing is published after the request. Database backups are rotated, so a deleted account disappears from them as older backups are replaced.</li>
  </ul>

  <h2>7. Your choices and how to delete your data</h2>
  <ul>
    <li>Delete projects and clips from your history at any time.</li>
    <li>Disconnect any TikTok account, YouTube channel, Facebook Page, Instagram account or Threads profile on the Connected accounts page. This stops all publishing to it and deletes the stored tokens.</li>
    <li>Revoke the Service's access on the platform itself: the <a href="https://security.google.com/settings/security/permissions" target="_blank" rel="noopener">Google security settings page</a>, the TikTok app's connected apps settings, Facebook <a href="https://www.facebook.com/settings?tab=business_tools" target="_blank" rel="noopener">Business Integrations</a>, and the Instagram <a href="https://www.instagram.com/accounts/manage_access/" target="_blank" rel="noopener">Apps and Websites</a> page.</li>
    <li>Delete your account yourself: open the account menu, choose Akun saya, enter your password and type the confirmation word. The account and all data linked to it are deleted immediately, without a waiting period, and you are signed out. This cannot be undone. Before deleting, the Service asks Google, TikTok and Facebook to revoke its access to your connected accounts; if a platform does not answer, the account is still deleted and you can remove the Service from that platform's settings yourself. Threads offers no way for an app to revoke itself, so remove Clipper Studio from the connected apps in your Threads settings. Posts already published to other platforms stay there.</li>
    <li>Ask us to access or correct your data, or to delete an account you can no longer sign in to, by contacting us below.</li>
  </ul>

  <h2>8. Security</h2>
  <p>The Service is served over HTTPS, passwords are hashed, platform tokens are encrypted in our database, and access to each account's projects is restricted to that account. No method of storage or transmission is completely secure, but we work to protect your information.</p>

  <h2>9. Children</h2>
  <p>The Service is not intended for children under 13 (or the minimum age required in your country) and we do not knowingly collect their information.</p>

  <h2>10. Changes</h2>
  <p>We may update this policy. The date at the top shows the latest version.</p>

  <h2>11. Contact</h2>
  <p>Privacy questions and requests: @include('public._contact')</p>
</article>
@endsection
