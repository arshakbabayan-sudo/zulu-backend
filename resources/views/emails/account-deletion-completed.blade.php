@extends('emails.layout')

@section('title', 'Account deleted')

@section('content')
    <h1 style="margin:0 0 16px;font-size:20px;color:#1A3C6E;">Your account has been deleted</h1>

    <p style="margin:0 0 16px;">Hi {{ $displayName ?: 'there' }},</p>

    <p style="margin:0 0 16px;">
        The 30-day grace period has elapsed and we've permanently removed
        your personal data from ZULU. Booking history and audit records
        that we're legally required to retain have been anonymised — your
        name and contact details no longer appear there.
    </p>

    <p style="margin:0 0 16px;">
        You're always welcome back — registration at zulu.am takes a minute.
    </p>

    <p style="margin:0;font-size:13px;color:#555;">
        Sent to {{ $emailAddress }} from the ZULU compliance system.
    </p>
@endsection
