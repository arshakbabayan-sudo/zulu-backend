@extends('emails.layout')

@section('title', 'Payment received — ZULU SPIN')

@section('content')
    <p style="margin:0 0 16px 0;">Hello,</p>
    <p style="margin:0 0 16px 0;">Your payment of <strong>{{ $payment->amount }} {{ $currency }}</strong> has been received.</p>
    <p style="margin:0;"><strong>Invoice reference:</strong> {{ $invoice->unique_booking_reference ?? '—' }}</p>
@endsection
