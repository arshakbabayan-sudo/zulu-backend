@extends('emails.layout')

@section('title', 'Booking confirmed — ZULU SPIN')

@section('content')
    <p style="margin:0 0 16px 0;">Dear {{ $user->name }},</p>
    <p style="margin:0 0 16px 0;">Your booking <strong>#{{ $booking->id }}</strong> has been confirmed.</p>
    <p style="margin:0 0 16px 0;"><strong>Total:</strong> {{ $booking->total_price }}</p>
    <p style="margin:0;">You can view your booking in your account.</p>
@endsection
