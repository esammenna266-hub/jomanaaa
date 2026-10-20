@extends('documents.layout')

@section('content')
    <div class="box"><b>بيانات السائق</b>
        <div class="grid">
            <div class="field"><b>الاسم:</b> {{ $driver->name }}</div>
            <div class="field"><b>التليفون:</b> <span class="num">{{ $driver->phone ?? '—' }}</span></div>
            <div class="field"><b>رقم الرخصة:</b> <span class="num">{{ $driver->license_no ?? '—' }}</span></div>
            <div class="field"><b>الهوية:</b> {{ $driver->id_type ? $idTypes[$driver->id_type] : '—' }} <span class="num">{{ $driver->id_no }}</span></div>
            <div class="field"><b>تاريخ الإصدار:</b> <span class="num">{{ $driver->id_issue_date?->format('Y/m/d') ?? '—' }}</span></div>
            <div class="field"><b>تاريخ الانتهاء:</b> <span class="num">{{ $driver->id_expiry_date?->format('Y/m/d') ?? '—' }}</span></div>
        </div>
    </div>

    <div class="box"><b>بيانات الرحلة</b>
        <div class="grid">
            <div class="field"><b>رقم الرحلة:</b> <span class="num">{{ $trip->serial_no }}</span></div>
            <div class="field"><b>السيارة:</b> <span class="num">{{ $trip->vehicle->plate_no }}</span></div>
            <div class="field"><b>نقطة الاستلام:</b> {{ $undertaking->pickup_point ?? '—' }}</div>
            <div class="field"><b>نقطة التسليم:</b> {{ $undertaking->delivery_point ?? '—' }}</div>
            <div class="field"><b>مدة التسليم:</b> @if ($undertaking->delivery_days)<span class="num">{{ $undertaking->delivery_days }}</span> يوم @else — @endif</div>
            <div class="field"><b>غرامة التأخير اليومية:</b>
                @if ($undertaking->daily_penalty !== null)<span class="num">{{ number_format((float) $undertaking->daily_penalty, 2) }}</span> {{ $currency }}@else — @endif
            </div>
        </div>
    </div>

    <p style="margin: 14px 0; font-size: 14px;">{{ $text }}</p>

    <div style="display:flex; justify-content:space-between; align-items:flex-end; margin-top:16px;">
        <div>
            <div class="small">تاريخ التوقيع: <span class="num">{{ $undertaking->signed_at?->format('Y/m/d H:i') }}</span></div>
            <div class="sign" style="margin-top:34px;"><div>توقيع السائق</div></div>
        </div>
        <div style="text-align:center;">
            <div class="small">بصمة السائق</div>
            @if ($fingerprint)<img class="fingerprint" src="{{ $fingerprint }}" alt="">@else<div class="box">—</div>@endif
        </div>
    </div>
@endsection
