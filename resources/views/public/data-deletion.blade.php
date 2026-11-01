@extends('public._layout')
@section('title', 'Data deletion status')

@section('style')
.dd-card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 20px 22px; margin: 24px 0 16px; }
.dd-card dl { display: grid; grid-template-columns: 170px minmax(0, 1fr); gap: 10px 16px; margin: 0; font-size: 15px; }
.dd-card dt { color: var(--text-muted); }
.dd-card dd { margin: 0; color: var(--text); overflow-wrap: anywhere; }
.dd-code { font-family: var(--font-mono); }
@media (max-width: 640px) {
  .dd-card dl { grid-template-columns: 1fr; gap: 2px; }
  .dd-card dd + dt { margin-top: 10px; }
}
@endsection

@section('content')
@php($platform = $deletion->provider === 'threads' ? 'Threads' : 'Facebook')
<article class="pb-doc">
  <h1>Data deletion status</h1>
  <p class="pb-updated">Request received through {{ $platform }}.</p>

  <div class="dd-card">
    <dl>
      <dt>Confirmation code</dt>
      <dd class="dd-code">{{ $deletion->confirmation_code }}</dd>
      <dt>Received</dt>
      <dd>{{ $deletion->created_at->toDayDateTimeString() }} (UTC)</dd>
      <dt>Status</dt>
      <dd>{{ $deletion->status === \App\Models\DataDeletionRequest::STATUS_COMPLETED ? 'Completed on '.$deletion->completed_at->toFormattedDateString() : 'In progress' }}</dd>
    </dl>
  </div>

  @if ($deletion->connections_removed > 0)
    <p>Clipper Studio deleted the {{ $platform }} connection{{ $deletion->connections_removed > 1 ? 's' : '' }} linked to your {{ $platform }} account: the access token, your {{ $platform }} user ID and name, and {{ $deletion->provider === 'threads' ? 'your Threads profile details' : 'every Facebook Page and Instagram account connected through it, with their tokens and details' }}.</p>
  @else
    <p>Clipper Studio held no data from your {{ $platform }} account when the request arrived, so there was nothing to delete.</p>
  @endif
  <p>Posts already published to {{ $deletion->provider === 'threads' ? 'Threads' : 'Facebook or Instagram' }} stay on that platform; delete them there if you want them gone. A Clipper Studio account (email and password) is separate from {{ $platform }} and is not deleted by this request. Its owner can delete it, with all its projects and clips, from the Akun saya page in the app's account menu. See the <a href="{{ route('privacy') }}">Privacy Policy</a> for details.</p>
</article>
@endsection
