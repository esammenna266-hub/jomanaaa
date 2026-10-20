<?php

namespace Database\Factories;

use App\Modules\Core\Models\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Branch>
 */
class BranchFactory extends Factory
{
    protected $model = Branch::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'فرع '.fake()->unique()->city(),
            'country' => 'EG',
            'is_main_warehouse' => false,
            'active' => true,
        ];
    }
}
