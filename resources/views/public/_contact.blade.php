@php($email = config('services.clipper.contact_email'))
@if ($email)<a href="mailto:{{ $email }}">{{ $email }}</a>.@else contact the administrator of this website.@endif
