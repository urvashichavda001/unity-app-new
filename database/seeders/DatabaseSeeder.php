<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(CategorySeeder::class);
        $this->call(AdSeeder::class);
        $this->call(GreenpreneurAppConfigSeeder::class);

        User::query()->updateOrCreate(
            ['id' => 'b5d96183-2278-4dfb-b450-ff8896347fe4'],
            [
                'first_name' => 'Tan',
                'last_name' => 'Hars',
                'display_name' => 'Tan Hars',
                'public_profile_slug' => 'tan-hars',
                'email' => 'adinkaanadin@gmail.com',
                'phone' => '531103',
                'company_name' => 'Peers Global Unity',
                'membership_status' => 'visitor',
                'coins_balance' => 0,
                'password_hash' => Hash::make('password123'),
                'created_at' => Carbon::parse('2025-12-22T22:09:09Z'),
                'updated_at' => Carbon::parse('2025-12-22T22:09:09Z'),
            ]
        );
    }
}
