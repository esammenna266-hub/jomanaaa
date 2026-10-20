<?php

namespace Database\Factories;

use App\Modules\Core\Models\Branch;
use App\Modules\MasterData\Models\Customer;
use App\Modules\Shipping\Models\Shipment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * @extends Factory<Shipment>
 */
class ShipmentFactory extends Factory
{
    protected $model = Shipment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'serial_no' => 'T'.fake()->unique()->numerify('######'),
            'tracking_code' => strtoupper(Str::random(10)),
            'type' => 'normal',
            'branch_id' => Branch::factory(),
            'customer_id' => Customer::factory(),
            'consignee_name' => fake()->name(),
            'consignee_phone' => '+249912345678',
            'destination_id' => DB::table('destinations')->orderBy('id')->value('id'),
            'currency_id' => 1,
            'price' => '1000.00',
            'discount' => '0.00',
            'status' => 'draft',
        ];
    }

    public function atBranch(Branch $branch): static
    {
        return $this->state(fn () => ['branch_id' => $branch->id, 'status' => 'at_branch']);
    }
}
