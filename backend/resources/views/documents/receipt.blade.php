@extends('documents.layout')

@section('content')
    <div class="grid">
        <div class="field"><b>استلمنا من السيد/ة:</b> {{ $shipment->customer->name }}</div>
        <div class="field"><b>استمارة شحن رقم:</b> <span class="num">{{ $shipment->serial_no }}</span></div>
        <div class="field"><b>طريقة الدفع:</b> {{ $method }}</div>
        <div class="field"><b>أمين الخزنة:</b> {{ $cashier?->name ?? '—' }}</div>
    </div>

    <div class="box">
        <div class="total">المبلغ: <span class="num">{{ number_format((float) $payment->amount, 2) }}</span> {{ $currencyName }}</div>
        <div>فقط: {{ $amountWords }}</div>
        @if ($payment->notes)<div class="small">{{ $payment->notes }}</div>@endif
    </div>

    @if ($balance)
        <table>
            <tr><th>إجمالي الشحنة</th><th>المدفوع</th><th>المتبقي</th></tr>
            <tr>
                <td><span class="num">{{ $balance['total_due'] }}</span> {{ $shipmentCurrency }}</td>
                <td><span class="num">{{ $balance['paid'] }}</span></td>
                <td><span class="num">{{ $balance['remaining'] }}</span></td>
            </tr>
        </table>
    @endif

    @if ($openInstallments->isNotEmpty())
        <div><b>جدول الأقساط المتبقية:</b></div>
        <table>
            <tr><th>#</th><th>تاريخ الاستحقاق</th><th>المبلغ</th></tr>
            @foreach ($openInstallments as $installment)
                <tr>
                    <td>{{ $loop->iteration }}</td>
                    <td><span class="num">{{ $installment->due_date->format('Y/m/d') }}</span></td>
                    <td><span class="num">{{ number_format((float) $installment->amount, 2) }}</span></td>
                </tr>
            @endforeach
        </table>
    @endif

    <div class="small">رقم التتبع: <span class="num">{{ $shipment->tracking_code }}</span> — <span class="num">{{ $trackingUrl }}</span></div>
    <div class="sign"><div>توقيع المستلم</div><div>الختم</div></div>
@endsection
