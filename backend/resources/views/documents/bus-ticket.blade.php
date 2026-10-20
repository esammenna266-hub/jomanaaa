@extends('documents.layout')

@push('styles')
    <style>
        header { flex-direction: column; gap: 4px; }
        .meta { text-align: right; }
        .grid { grid-template-columns: 1fr; }
        .seat { font-size: 30px; font-weight: 800; text-align: center; border: 2px solid #1b3a5c; border-radius: 6px; padding: 2px 0; margin: 8px 0; }
        .stub { border-top: 2px dashed #555; margin-top: 14px; padding-top: 8px; font-size: 10px; }
    </style>
@endpush

@section('content')
    <div class="grid">
        <div class="field"><b>الراكب:</b> {{ $booking->passenger_name }}</div>
        @if ($booking->passport_no)
            <div class="field"><b>الجواز:</b> <span class="num">{{ $booking->passport_no }}</span></div>
        @endif
        <div class="field"><b>الرحلة:</b> {{ $trip->route }}</div>
        @if ($booking->destination)
            <div class="field"><b>الوجهة:</b> {{ $booking->destination }}</div>
        @endif
        <div class="field"><b>التحرك:</b> <span class="num">{{ $trip->depart_at->timezone('Africa/Cairo')->format('Y/m/d H:i') }}</span></div>
        @if ($trip->checkin_at)
            <div class="field"><b>الحضور:</b> <span class="num">{{ $trip->checkin_at->timezone('Africa/Cairo')->format('Y/m/d H:i') }}</span></div>
        @endif
        @if ($trip->vehicle)
            <div class="field"><b>الأتوبيس:</b> <span class="num">{{ $trip->vehicle->plate_no }}</span></div>
        @endif
    </div>

    <div class="seat">كرسي <span class="num">{{ $booking->seat_no }}</span></div>

    <div class="total">السعر: <span class="num">{{ number_format((float) $booking->sale_price, 2) }}</span> {{ $currency }}</div>

    <div class="stub">
        <div><b>كعب التذكرة</b> — <span class="num">{{ $booking->serial_no }}</span></div>
        <div>{{ $booking->passenger_name }} — كرسي <span class="num">{{ $booking->seat_no }}</span> — {{ $trip->route }}</div>
    </div>
@endsection
