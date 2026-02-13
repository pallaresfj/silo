<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    /**
     * Seed the default administrator (Rector) user.
     */
    public function run(): void
    {
        User::firstOrCreate(
            ['email' => 'rectoria@iedagropivijay.edu.co'],
            [
                'name' => 'Francisco Pallares De la Hoz',
                'password' => Hash::make('pass1234'),
                'role' => 'rector',
            ]
        );
    }
}
