@extends('documents.layout')

@section('content')
    <div class="grid">
        <div class="field"><b>السيارة:</b> <span class="num">{{ $trip->vehicle->plate_no }}</span></div>
        <div class="field"><b>السائق:</b> {{ $trip->driver->name }} <span class="num">{{ $trip->driver->phone }}</span></div>
        <div class="field"><b>المنفذ:</b> {{ $trip->borderPoint?->name ?? '—' }}</div>
        <div class="field"><b>المخلص:</b> {{ $trip->clearingAgent?->name ?? '—' }}</div>
    </div>

    <table>
        <thead>
        <tr><th style="width:4%">#</th><th style="width:10%">استمارة</th><th>المرسل</th><th>المستلم</th><th>الوجهة</th><th>البضاعة</th></tr>
        </thead>
        <tbody>
        @foreach ($trip->shipments as $shipment)
            <tr>
                <td>{{ $loop->iteration }}</td>
                <td><span class="num">{{ $shipment->serial_no }}</span></td>
                <td>{{ $shipment->customer->name }}</td>
                <td>{{ $shipment->consignee_name }}<br><span class="num small">{{ $shipment->consignee_phone }}</span></td>
                <td>{{ $shipment->destination?->name }}</td>
                <td>
                    @foreach ($shipment->items as $item)
                        {{ $item->name }} — <span class="num">{{ (float) $item->qty }}</span> {{ $units[$item->unit] }}<br>
                    @endforeach
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <div class="box">
        <b>الإجماليات:</b>
        عدد الشحنات <span class="num">{{ $trip->shipments->count() }}</span>
        @foreach ($totalsByUnit as $unit => $total)
            — {{ $units[$unit] }}: <span class="num">{{ rtrim(rtrim(number_format($total, 2, '.', ''), '0'), '.') }}</span>
        @endforeach
        @if ($totalWeight > 0) — الوزن: <span class="num">{{ number_format($totalWeight, 2) }}</span> كجم @endif
    </div>

    <div class="sign"><div>السائق</div><div>أمين المخزن</div><div>الختم</div></div>
@endsection
