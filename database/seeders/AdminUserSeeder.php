<?php

namespace Database\Seeders;

use App\Models\AllowedGoogleAccount;
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

        AllowedGoogleAccount::query()->updateOrCreate(
            ['email' => 'rectoria@iedagropivijay.edu.co'],
            [
                'default_role_slug' => 'rector',
                'is_active' => true,
                'notes' => 'Cuenta administrativa inicial',
            ]
        );
    }
}
