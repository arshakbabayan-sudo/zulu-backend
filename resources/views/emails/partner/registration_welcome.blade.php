@extends('emails.layout')

@section('title', 'Welcome to ZULU SPIN')

@section('content')
<h1 style="margin:0 0 16px 0;font-size:22px;color:#1A3C6E;line-height:1.3;">
    Welcome, {{ $user->name }}!
</h1>

<p style="margin:0 0 16px 0;">
    Thanks for creating your ZULU SPIN partner account. We've registered you as
    a <strong>{{ $intendedRole === 'agent' ? 'tour agent' : 'tour operator' }}</strong>.
</p>

<p style="margin:0 0 16px 0;">
    Your account is active, and the password you chose at sign-up works
    immediately — but to start selling on ZULU you still need to submit your
    company application. We'll review the documents and confirm by email
    once you're approved.
</p>

<p style="margin:24px 0;">
    <a href="{{ $applyUrl }}"
       style="display:inline-block;padding:12px 24px;background-color:#923081;color:#ffffff;text-decoration:none;border-radius:24px;font-weight:600;">
        Complete my application
    </a>
</p>

<p style="margin:0 0 8px 0;font-size:14px;color:#666666;">
    Or paste this link in your browser:
</p>
<p style="margin:0 0 16px 0;font-size:13px;word-break:break-all;color:#923081;">
    {{ $applyUrl }}
</p>

<p style="margin:24px 0 0 0;font-size:13px;color:#666666;">
    If you didn't sign up for ZULU SPIN, you can safely ignore this email — no
    further action is required.
</p>
@endsection
