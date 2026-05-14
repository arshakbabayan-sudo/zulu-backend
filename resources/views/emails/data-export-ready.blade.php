@extends('emails.layout')

@section('title', 'Your data export is ready')

@section('content')
    <h1 style="margin:0 0 16px;font-size:20px;color:#1A3C6E;">Your data export is ready</h1>

    <p style="margin:0 0 16px;">Hi {{ $user->name ?: 'there' }},</p>

    <p style="margin:0 0 16px;">
        We've packaged every record we hold about your ZULU account into
        a single ZIP archive. The download link below is valid for 7 days.
    </p>

    <p style="margin:24px 0;">
        <a href="{{ $downloadUrl }}"
           style="background-color:#923081;color:#ffffff;padding:12px 20px;text-decoration:none;border-radius:6px;display:inline-block;font-weight:600;">
            Download my data
        </a>
    </p>

    <p style="margin:0 0 16px;font-size:13px;color:#555;">
        If you didn't request this, ignore this email and let support know.
    </p>
@endsection
