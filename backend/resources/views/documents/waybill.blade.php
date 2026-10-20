@extends('documents.layout')

@section('content')
    <div class="small">ورقة <span class="num">{{ $sheet['number'] }}</span> من <span class="num">{{ $sheet['of'] }}</span> — رحلة <span class="num">{{ $trip->serial_no }}</span> — استمارة <span class="num">{{ $shipment->serial_no }}</span></div>

    <div class="grid">
        <div class="field"><b>المرسل:</b> {{ $shipment->customer->name }}</div>
        <div class="field"><b>المستلم:</b> {{ $shipment->consignee_name }}</div>
        <div class="field"><b>تليفون المستلم:</b> <span class="num">{{ $shipment->consignee_phone }}</span></div>
        <div class="field"><b>الوجهة:</b> {{ $shipment->destination?->name }}</div>
        <div class="field"><b>السيارة:</b> <span class="num">{{ $trip->vehicle->plate_no }}</span></div>
        <div class="field"><b>السائق:</b> {{ $trip->driver->name }}</div>
        <div class="field"><b>المنفذ:</b> {{ $trip->borderPoint?->name ?? '—' }}</div>
        <div class="field"><b>المخلص:</b> {{ $trip->clearingAgent?->name ?? '—' }}</div>
    </div>

    <table>
        <thead><tr><th>الصنف</th><th style="width:14%">العدد</th><th style="width:16%">الوحدة</th><th style="width:16%">الوزن (كجم)</th></tr></thead>
        <tbody>
        @foreach ($shipment->items as $item)
            <tr>
                <td>{{ $item->name }}</td>
                <td><span class="num">{{ (float) $item->qty }}</span></td>
                <td>{{ ['piece' => 'قطعة', 'carton' => 'كرتونة', 'sack' => 'شوال', 'barrel' => 'برميل', 'pack' => 'عبوة'][$item->unit] }}</td>
                <td><span class="num">{{ $item->weight_kg !== null ? (float) $item->weight_kg : '—' }}</span></td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <div style="display:flex; justify-content:space-between; align-items:flex-end; margin-top:10px;">
        <div class="small">بصمة السائق</div>
        @if ($fingerprint)<img class="fingerprint" src="{{ $fingerprint }}" alt="">@else<div class="box">—</div>@endif
    </div>
    <div class="sign"><div>توقيع المستلم</div><div>الختم</div></div>
@endsection
