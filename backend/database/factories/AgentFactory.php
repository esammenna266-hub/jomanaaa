<?php

namespace Database\Factories;

use App\Modules\Core\Models\Agent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Agent>
 */
class AgentFactory extends Factory
{
    protected $model = Agent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'مكتب '.fake()->unique()->company(),
            'country' => 'SD',
            'active' => true,
        ];
    }
}
