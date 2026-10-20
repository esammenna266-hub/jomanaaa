<?php

namespace Database\Factories;

use App\Modules\Booking\Models\Booking;
use App\Modules\Booking\Models\PassengerTrip;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Booking>
 */
class BookingFactory extends Factory
{
    protected $model = Booking::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'serial_no' => 'B'.fake()->unique()->numerify('######'),
            'trip_id' => PassengerTrip::factory(),
            'seat_no' => fake()->unique()->numberBetween(1, 48),
            'passenger_name' => fake()->name(),
            'sale_price' => '2500.00',
            'currency_id' => 1,
            'status' => 'paid',
        ];
    }
}
