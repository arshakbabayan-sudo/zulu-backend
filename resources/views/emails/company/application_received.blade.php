@extends('emails.layout')

@section('title', 'We received your application — ZULU SPIN')

@section('content')
    <p style="margin:0 0 16px 0;">Dear {{ $application->contact_person }},</p>
    <p style="margin:0 0 16px 0;">We have received your application for <strong>{{ $application->company_name }}</strong>. Our team will review it within 2–3 business days. You will be notified by email.</p>
    <p style="margin:0;">Thank you for your interest in ZULU SPIN.</p>
@endsection
