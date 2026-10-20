@extends('documents.layout')

@section('content')
    <div class="grid">
        <div class="field"><b>الفرع:</b> {{ $branch?->name }}</div>
        <div class="field"><b>النوع:</b> {{ $shipment->type === 'express' ? 'شحن سريع' : 'شحن عادي' }}</div>
        <div class="field"><b>العميل:</b> {{ $shipment->customer->name }}</div>
        <div class="field"><b>تليفون العميل:</b> <span class="num">{{ $shipment->customer->phone }}</span></div>
        <div class="field"><b>المستلم:</b> {{ $shipment->consignee_name }}</div>
        <div class="field"><b>تليفون المستلم:</b> <span class="num">{{ $shipment->consignee_phone }}</span></div>
        <div class="field"><b>الوجهة:</b> {{ $shipment->destination?->name }}</div>
        <div class="field"><b>المنفذ:</b> {{ $shipment->borderPoint?->name ?? '—' }}</div>
        <div class="field"><b>المخلص:</b> {{ $shipment->clearingAgent?->name ?? '—' }}</div>
        <div class="field"><b>التاريخ المتوقع:</b> <span class="num">{{ $shipment->expected_date?->format('Y/m/d') ?? '—' }}</span></div>
        @if ($shipment->proposed_category)
            <div class="field"><b>العربية المقترحة:</b>
                {{ ['jumbo' => 'جامبو', 'single' => 'سنجل', 'trailer' => 'تريلا', 'bus' => 'أتوبيس'][$shipment->proposed_category] }}
                {{ $shipment->proposed_body ? ['closed' => 'مقفولة', 'open' => 'مفتوحة'][$shipment->proposed_body] : '' }}
                @if ($shipment->proposed_tons) — <span class="num">{{ (float) $shipment->proposed_tons }}</span> طن @endif
            </div>
        @endif
    </div>

    <table>
        <thead>
        <tr><th style="width:5%">#</th><th>الصنف</th><th style="width:12%">العدد</th><th style="width:14%">الوحدة</th><th style="width:12%">الوزن (كجم)</th><th>ملاحظات</th></tr>
        </thead>
        <tbody>
        @foreach ($shipment->items as $item)
            <tr>
                <td>{{ $loop->iteration }}</td>
                <td>{{ $item->name }}</td>
                <td><span class="num">{{ (float) $item->qty }}</span></td>
                <td>{{ ['piece' => 'قطعة', 'carton' => 'كرتونة', 'sack' => 'شوال', 'barrel' => 'برميل', 'pack' => 'عبوة'][$item->unit] }}</td>
                <td><span class="num">{{ $item->weight_kg !== null ? (float) $item->weight_kg : '—' }}</span></td>
                <td>{{ $item->notes }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <div class="box">
        @if ($isAgent)
            <div class="total">سعر الشحن: <span class="num">{{ number_format((float) $shipment->agent_sale_price, 2) }}</span> {{ $currency }}</div>
        @else
            <div>السعر: <span class="num">{{ number_format((float) $shipment->price, 2) }}</span> {{ $currency }}</div>
            @if ((float) $shipment->discount > 0)
                <div>الخصم: <span class="num">{{ number_format((float) $shipment->discount, 2) }}</span> {{ $currency }}</div>
            @endif
            <div class="total">الصافي: <span class="num">{{ number_format((float) $shipment->price - (float) $shipment->discount, 2) }}</span> {{ $currency }}</div>
        @endif
    </div>

    @if ($shipment->notes)
        <div class="box"><b>ملاحظات:</b> {{ $shipment->notes }}</div>
    @endif

    <div class="small">رقم التتبع: <span class="num">{{ $shipment->tracking_code }}</span> — تابع شحنتك: <span class="num">{{ $trackingUrl }}</span></div>

    <div class="sign"><div>توقيع العميل</div><div>موظف الفرع</div><div>الختم</div></div>
@endsection
