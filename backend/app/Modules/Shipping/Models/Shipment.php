<?php

namespace App\Modules\Shipping\Models;

use App\Modules\Booking\Models\PassengerTrip;
use App\Modules\Core\Models\Branch;
use App\Modules\Finance\Models\Installment;
use App\Modules\Finance\Models\Payment;
use App\Modules\MasterData\Models\BorderPoint;
use App\Modules\MasterData\Models\ClearingAgent;
use App\Modules\MasterData\Models\Customer;
use App\Modules\MasterData\Models\Destination;
use App\Modules\Trips\Models\ExpenseAllocation;
use App\Support\Auditing\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Shipment extends Model
{
    use Auditable, HasFactory;

    /** Statuses in which money can be collected (after the price is approved, before cancellation). */
    public const COLLECTABLE = [
        'approved', 'at_branch', 'to_main_warehouse', 'at_main_warehouse', 'loaded', 'in_transit', 'arrived', 'delivered',
    ];

    /** Cancellation is allowed up to (and including) the main warehouse, never once loaded (TR-05). */
    public const CANCELLABLE = [
        'draft', 'pending_approval', 'approved', 'at_branch', 'to_main_warehouse', 'at_main_warehouse',
    ];

    protected $table = 'shipments';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'discount' => 'decimal:2',
            'agent_sale_price' => 'decimal:2',
            'proposed_tons' => 'decimal:2',
            'expected_date' => 'date:Y-m-d',
            'delivered_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function destination(): BelongsTo
    {
        return $this->belongsTo(Destination::class);
    }

    /** The bus trip an express shipment rides on. */
    public function busTrip(): BelongsTo
    {
        return $this->belongsTo(PassengerTrip::class, 'bus_trip_id');
    }

    public function borderPoint(): BelongsTo
    {
        return $this->belongsTo(BorderPoint::class);
    }

    public function clearingAgent(): BelongsTo
    {
        return $this->belongsTo(ClearingAgent::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ShipmentItem::class);
    }

    public function installments(): HasMany
    {
        return $this->hasMany(Installment::class)->orderBy('due_date');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->orderBy('id');
    }

    public function expenseAllocations(): HasMany
    {
        return $this->hasMany(ExpenseAllocation::class);
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(Approval::class, 'subject_id')->where('subject_type', 'shipment');
    }
}
