<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

class AdminUserSeeder extends Seeder
{
    /**
     * Seed the default administrator (Rector) user.
     */
    public function run(): void
    {
        $user = User::firstOrCreate(
            ['email' => 'rectoria@iedagropivijay.edu.co'],
            [
                'name' => 'Francisco Pallares De la Hoz',
                'password' => null,
                'role' => 'rector',
            ]
        );

        $role = Role::query()->where('slug', 'rector')->first();

        if ($role) {
            $user->roles()->syncWithoutDetaching([$role->id]);
        }
    }
}
