@extends('emails.layout')

@section('title', 'Confirm account deletion')

@section('content')
    <h1 style="margin:0 0 16px;font-size:20px;color:#1A3C6E;">Confirm account deletion</h1>

    <p style="margin:0 0 16px;">Hi {{ $user->name ?: 'there' }},</p>

    <p style="margin:0 0 16px;">
        We received a request to delete your ZULU account. To confirm,
        click the button below. After you confirm we'll deactivate your
        account immediately and permanently remove it 30 days later
        unless you log back in and cancel.
    </p>

    <p style="margin:24px 0;">
        <a href="{{ $confirmUrl }}"
           style="background-color:#923081;color:#ffffff;padding:12px 20px;text-decoration:none;border-radius:6px;display:inline-block;font-weight:600;">
            Confirm deletion
        </a>
    </p>

    <p style="margin:0 0 16px;font-size:13px;color:#555;">
        If you didn't request this, ignore this email and your account
        stays intact. The link expires after a single click.
    </p>
@endsection
