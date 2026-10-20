<?php

namespace Database\Factories;

use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'username' => fake()->unique()->userName(),
            'password_hash' => 'password',
            'role_id' => Role::where('code', 'branch_staff')->value('id'),
            'active' => true,
            'must_change_password' => false,
        ];
    }

    public function withRole(string $code): static
    {
        return $this->state(fn () => ['role_id' => Role::where('code', $code)->value('id')]);
    }
}
