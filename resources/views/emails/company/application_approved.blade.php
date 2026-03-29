@extends('emails.layout')

@section('title', 'Your application has been approved — ZULU SPIN')

@section('content')
    <p style="margin:0 0 16px 0;">Dear {{ $application->contact_person }},</p>
    <p style="margin:0 0 16px 0;">Your application for <strong>{{ $application->company_name }}</strong> has been approved.</p>
    <p style="margin:0 0 8px 0;"><strong>Your login credentials</strong></p>
    <p style="margin:0 0 8px 0;">Email: {{ $user->email }}</p>
    <p style="margin:0 0 20px 0;">Temporary password: <strong>{{ $temporaryPassword }}</strong></p>
    <p style="margin:0 0 24px 0;">Please log in and change your password immediately.</p>
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0;">
        <tr>
            <td align="left" style="border-radius:6px;background-color:#1A3C6E;">
                <a href="{{ $loginUrl }}" target="_blank" rel="noopener noreferrer" style="display:inline-block;padding:12px 24px;font-size:15px;font-weight:bold;color:#ffffff;text-decoration:none;">Login Now</a>
            </td>
        </tr>
    </table>
@endsection
