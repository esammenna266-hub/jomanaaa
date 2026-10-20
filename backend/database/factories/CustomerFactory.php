<?php

namespace Database\Factories;

use App\Modules\MasterData\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'phone' => '+2010'.fake()->unique()->numerify('########'),
            'country' => 'EG',
            'active' => true,
        ];
    }
}
