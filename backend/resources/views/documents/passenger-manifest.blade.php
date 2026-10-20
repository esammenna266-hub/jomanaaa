@extends('documents.layout')

@section('content')
    <div class="grid">
        <div class="field"><b>النوع:</b> {{ $kinds[$trip->kind] }}</div>
        <div class="field"><b>الرحلة:</b> {{ $trip->route }}</div>
        <div class="field"><b>التحرك:</b> <span class="num">{{ $trip->depart_at->timezone('Africa/Cairo')->format('Y/m/d H:i') }}</span></div>
        <div class="field"><b>الأتوبيس:</b> <span class="num">{{ $trip->vehicle?->plate_no ?? '—' }}</span></div>
        <div class="field"><b>السائق:</b> {{ $trip->driver?->name ?? '—' }}</div>
        <div class="field"><b>عدد الركاب:</b> <span class="num">{{ $passengers->count() }}</span> من <span class="num">{{ $trip->passenger_seats }}</span></div>
    </div>

    <table>
        <thead>
        <tr><th style="width:8%">الكرسي</th><th>الاسم</th><th style="width:22%">الجواز</th><th style="width:22%">الوجهة</th></tr>
        </thead>
        <tbody>
        @foreach ($passengers as $passenger)
            <tr>
                <td><span class="num">{{ $passenger->seat_no }}</span></td>
                <td>{{ $passenger->passenger_name }}</td>
                <td><span class="num">{{ $passenger->passport_no ?? '—' }}</span></td>
                <td>{{ $passenger->destination ?? '—' }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <div class="sign"><div>السائق</div><div>مسؤول الحضور</div><div>الختم</div></div>
@endsection
