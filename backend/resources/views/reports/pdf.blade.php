<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'Cairo', 'Noto Naskh Arabic', 'Segoe UI', Tahoma, Arial, sans-serif; font-size: {{ $landscape ? '11.5px' : '12.5px' }}; color: #111; margin: 0; line-height: 1.55; }
        header { display: flex; justify-content: space-between; align-items: flex-end; border-bottom: 2px solid #1b3a5c; padding-bottom: 6px; margin-bottom: 10px; }
        .brand { display: flex; align-items: center; gap: 10px; }
        img.logo { height: 56px; max-width: 130px; object-fit: contain; }
        .company { font-size: 20px; font-weight: 700; color: #1b3a5c; }
        .report-title { font-size: 17px; font-weight: 700; margin-top: 2px; }
        .notes { text-align: left; font-size: 10.5px; color: #444; line-height: 1.7; }
        table { width: 100%; border-collapse: collapse; }
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; }
        th, td { border: 1px solid #888; padding: 4px 7px; text-align: right; vertical-align: top; }
        th { background: #1b3a5c; color: #fff; font-weight: 700; text-align: center; }
        tbody tr:nth-child(even) td { background: #f4f7fa; }
        td.number { direction: ltr; unicode-bidi: embed; text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
        td.empty { text-align: center; color: #666; padding: 18px; }
        .count { margin-top: 8px; font-size: 10.5px; color: #444; }
    </style>
</head>
<body>
@php($logo = \App\Support\Branding::logoDataUri())
<header>
    <div class="brand">
        @if ($logo)
            <img class="logo" src="{{ $logo }}" alt="">
        @endif
        <div>
            <div class="company">{{ $company['name'] }}</div>
            <div class="report-title">{{ $title }}</div>
        </div>
    </div>
    <div class="notes">
        @foreach ($notes as $note)
            <div>{{ $note }}</div>
        @endforeach
    </div>
</header>

<table>
    <thead>
    <tr>
        @foreach ($columns as $column)
            <th>{{ $column['label'] }}</th>
        @endforeach
    </tr>
    </thead>
    <tbody>
    @forelse ($rows as $row)
        <tr>
            @foreach ($columns as $column)
                @php($value = $row[$column['key']] ?? '')
                @if (($column['type'] ?? 'text') === 'money' && is_numeric($value))
                    <td class="number">{{ number_format((float) $value, 2) }}</td>
                @elseif (($column['type'] ?? 'text') === 'int' && is_numeric($value))
                    <td class="number">{{ number_format((float) $value, 0) }}</td>
                @else
                    <td>{{ $value }}</td>
                @endif
            @endforeach
        </tr>
    @empty
        <tr><td class="empty" colspan="{{ count($columns) }}">مفيش بيانات</td></tr>
    @endforelse
    </tbody>
</table>
<div class="count">عدد الصفوف: <span style="direction:ltr;unicode-bidi:embed;display:inline-block">{{ count($rows) }}</span></div>
</body>
</html>
