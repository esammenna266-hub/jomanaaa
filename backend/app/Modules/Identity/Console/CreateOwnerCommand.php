<?php

namespace App\Modules\Identity\Console;

use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class CreateOwnerCommand extends Command
{
    protected $signature = 'gomana:create-owner {username : Login name} {--name= : Display name}';

    protected $description = 'Create the first owner account (run once after migrating)';

    public function handle(): int
    {
        $ownerRoleId = Role::where('code', 'owner')->value('id');

        if (User::where('role_id', $ownerRoleId)->exists()) {
            $this->error('An owner account already exists. Manage users from the app.');

            return self::FAILURE;
        }

        $password = (string) $this->secret('Password (min 8 characters)');

        $validator = Validator::make(
            ['username' => $this->argument('username'), 'password' => $password],
            ['username' => ['required', 'alpha_dash', 'max:60'], 'password' => ['required', 'min:8']],
        );

        if ($validator->fails()) {
            $this->error($validator->errors()->first());

            return self::FAILURE;
        }

        User::create([
            'name' => $this->option('name') ?: $this->argument('username'),
            'username' => $this->argument('username'),
            'password_hash' => $password,
            'role_id' => $ownerRoleId,
            'must_change_password' => false,
        ]);

        $this->info('Owner account created.');

        return self::SUCCESS;
    }
}
