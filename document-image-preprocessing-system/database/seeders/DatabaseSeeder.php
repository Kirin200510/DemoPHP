<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);

        $demoAccounts = [
            [
                'name' => 'Demo Admin',
                'email' => 'admin@example.com',
                'password' => 'Admin@12345',
                'role' => 'admin',
            ],
            [
                'name' => 'Demo Processor',
                'email' => 'processor@example.com',
                'password' => 'Processor@12345',
                'role' => 'processor',
            ],
            [
                'name' => 'Test User',
                'email' => 'test@example.com',
                'password' => 'password',
                'role' => 'customer',
            ],
        ];

        foreach ($demoAccounts as $account) {
            $user = User::updateOrCreate(
                ['email' => $account['email']],
                [
                    'name' => $account['name'],
                    'password' => Hash::make($account['password']),
                    'is_active' => true,
                ],
            );

            $user->syncRoles([$account['role']]);
        }
    }
}
