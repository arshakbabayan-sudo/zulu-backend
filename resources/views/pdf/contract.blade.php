<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Service Agreement — {{ $company->name }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; line-height: 1.55; color: #222; margin: 28px; }
        .header-row { text-align: center; margin-bottom: 22px; border-bottom: 2px solid #1A3C6E; padding-bottom: 12px; }
        .logo { font-size: 22px; font-weight: bold; color: #1A3C6E; }
        .doc-title { font-size: 15px; font-weight: bold; margin-top: 8px; letter-spacing: 0.5px; }
        .section { margin-bottom: 14px; }
        .section-title { font-weight: bold; color: #1A3C6E; margin-bottom: 6px; }
        ul.terms { margin: 8px 0 0 18px; padding: 0; }
        ul.terms li { margin-bottom: 6px; }
        .party { margin: 10px 0; padding: 10px; background: #f8fafc; border: 1px solid #e2e8f0; }
        .sig-wrap { width: 100%; margin-top: 36px; overflow: hidden; }
        .sig-col { float: left; width: 48%; }
        .sig-line { border-top: 1px solid #333; margin-top: 36px; padding-top: 4px; width: 90%; }
        .footer { margin-top: 32px; font-size: 9px; color: #666; text-align: center; border-top: 1px solid #ccc; padding-top: 10px; }
        .services-list { margin: 6px 0 0 18px; }
    </style>
</head>
<body>
    <div class="header-row">
        <div class="logo">ZULU SPIN</div>
        <div class="doc-title">SERVICE AGREEMENT</div>
    </div>

    <div class="section">
        <strong>Agreement date:</strong> {{ $agreementDate->format('Y-m-d') }}<br>
        <strong>Effective from:</strong> {{ $effectiveFrom->format('Y-m-d') }}<br>
        <strong>Expires:</strong> {{ $expiresAt->format('Y-m-d') }}
    </div>

    <div class="section">
        <div class="section-title">Party A — Platform operator</div>
        <div class="party">
            <strong>ZULU SPIN</strong> (platform operator)<br>
            Contact: support@zuluspin.com
        </div>
    </div>

    <div class="section">
        <div class="section-title">Party B — Company</div>
        <div class="party">
            <strong>{{ $company->name }}</strong>
            @if($company->legal_name)<br>Legal name: {{ $company->legal_name }}@endif
            @if($company->tax_id)<br>Tax ID: {{ $company->tax_id }}@endif
            @if($company->address)<br>Address: {{ $company->address }}@endif
        </div>
    </div>

    <div class="section">
        <div class="section-title">Authorized services</div>
        @if(count($serviceTypes) === 0)
            <p>General B2B Access</p>
        @else
            <ul class="services-list">
                @foreach($serviceTypes as $type)
                    <li>{{ $type }}</li>
                @endforeach
            </ul>
        @endif
    </div>

    <div class="section">
        <div class="section-title">Standard terms</div>
        <ul class="terms">
            <li>The Company agrees to operate within ZULU SPIN platform rules.</li>
            <li>Commission rates are as defined in the platform settings.</li>
            <li>This agreement renews automatically every 6 months.</li>
            <li>Either party may terminate with 30 days written notice.</li>
            <li>All disputes governed by applicable law.</li>
        </ul>
    </div>

    <div class="sig-wrap">
        <div class="sig-col">
            <strong>ZULU SPIN Authorized Signature</strong>
            <div class="sig-line"></div>
            <div style="margin-top:8px;">Name: _________________________</div>
            <div style="margin-top:4px;">Date: _________________________</div>
        </div>
        <div class="sig-col" style="float:right;">
            <strong>Company Representative</strong>
            <div class="sig-line"></div>
            <div style="margin-top:8px;">Name: _________________________</div>
            <div style="margin-top:4px;">Date: _________________________</div>
        </div>
        <div style="clear:both;"></div>
    </div>

    <div class="footer">
        © ZULU SPIN Platform. Document ID: {{ $documentId }}
    </div>
</body>
</html>
