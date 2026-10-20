<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <title>{{ $document['title'] }} {{ $document['serial'] }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'Cairo', 'Noto Naskh Arabic', 'Segoe UI', Tahoma, Arial, sans-serif; font-size: {{ ['A5' => '11.5px', 'TICKET' => '11px'][$document['paper']] ?? '13px' }}; color: #111; margin: 0; line-height: 1.6; }
        header { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #1b3a5c; padding-bottom: 6px; margin-bottom: 10px; }
        .company { font-size: 20px; font-weight: 700; color: #1b3a5c; }
        .doc-title { font-size: 16px; font-weight: 700; margin-top: 2px; }
        .meta { text-align: left; }
        .serial { font-size: 17px; font-weight: 700; color: #b00020; direction: ltr; unicode-bidi: embed; }
        .small { font-size: 10.5px; color: #555; }
        table { width: 100%; border-collapse: collapse; margin: 6px 0; }
        th, td { border: 1px solid #888; padding: 3px 6px; text-align: right; vertical-align: top; }
        th { background: #eef2f6; font-weight: 700; }
        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 3px 18px; }
        .field b { color: #444; font-weight: 600; }
        .box { border: 1px solid #888; padding: 6px 8px; margin: 8px 0; }
        .num { direction: ltr; unicode-bidi: embed; display: inline-block; }
        .total { font-size: 14px; font-weight: 700; }
        .sign { display: flex; justify-content: space-between; margin-top: 26px; }
        .sign div { width: 30%; text-align: center; border-top: 1px solid #444; padding-top: 3px; }
        .print-note { color: #b00020; font-weight: 700; }
        footer { margin-top: 14px; border-top: 1px solid #888; padding-top: 4px; font-size: 10px; color: #444; text-align: center; }
        img.fingerprint { max-height: 90px; border: 1px solid #aaa; }
    </style>
    @stack('styles')
</head>
<body>
<header>
    <div>
        <div class="company">{{ $company['name'] }}</div>
        <div class="doc-title">{{ $document['title'] }}</div>
        @if ($document['version'] > 1)
            <div class="print-note">نسخة معدلة رقم {{ $document['version'] }}</div>
        @endif
    </div>
    <div class="meta">
        <div class="serial">{{ $document['serial'] }}</div>
        <div class="small">التاريخ: <span class="num">{{ $document['generated_at'] }}</span></div>
    </div>
</header>

@yield('content')

<footer>
    @if ($company['address']) {{ $company['address'] }} — @endif
    @if ($company['phones']) تليفون: <span class="num">{{ $company['phones'] }}</span> — @endif
    س.ت: <span class="num">{{ $company['commercial_register'] }}</span> — ب.ض: <span class="num">{{ $company['tax_card'] }}</span>
</footer>
</body>
</html>
