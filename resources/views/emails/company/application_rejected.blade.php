@extends('emails.layout')

@section('title', 'Application status update — ZULU SPIN')

@section('content')
    <p style="margin:0 0 16px 0;">Dear {{ $application->contact_person }},</p>
    <p style="margin:0 0 16px 0;">Unfortunately your application for <strong>{{ $application->company_name }}</strong> has been rejected.</p>
    <p style="margin:0 0 16px 0;"><strong>Reason:</strong> {{ $application->rejection_reason ?? 'Not specified.' }}</p>
    <p style="margin:0;">You may contact us for more information.</p>
@endsection
