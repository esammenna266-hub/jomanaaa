<?php

namespace Database\Factories;

use App\Modules\Booking\Models\PassengerTrip;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PassengerTrip>
 */
class PassengerTripFactory extends Factory
{
    protected $model = PassengerTrip::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'kind' => 'bus',
            'route' => 'القاهرة - الخرطوم',
            'depart_at' => now()->addDays(3),
            'checkin_at' => now()->addDays(3)->subHours(3),
            'capacity' => 52,
            'passenger_seats' => 48,
            'express_seats' => 4,
            'price' => '2500.00',
            'currency_id' => 1,
            'status' => 'open',
        ];
    }
}
