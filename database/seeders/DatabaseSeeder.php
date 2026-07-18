<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::updateOrCreate(
            ['id' => 1],
            [
                'name' => 'Swaraj Demo',
                'email' => 'swaraj@demo.com',
                'password' => bcrypt('password'),
            ]
        );

        User::updateOrCreate(
            ['id' => 2],
            [
                'name' => 'Bari Demo',
                'email' => 'bari@demo.com',
                'password' => bcrypt('password'),
            ]
        );
    }
}
