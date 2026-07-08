<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class AdminUserSeeder extends Seeder
{
    /**
     * @var array<int, array{name: string, email: string, password: string}>
     */
    private array $admins = [
        ['name' => 'paciorekj', 'email' => 'julkadabrowa2002@gmail.com', 'password' => 'Milanowska137!'],
        ['name' => 'paciorekg', 'email' => 'gregor1142@gmail.com', 'password' => 'Polak1234!'],
        ['name' => 'talarekr', 'email' => 'talarekr@gmail.com', 'password' => 'talarekr!'],
    ];

    public function run(): void
    {
        $adminRole = Role::findOrCreate(UserRole::OwnerAdmin->value, 'web');

        foreach ($this->admins as $admin) {
            $user = User::query()
                ->where('email', $admin['email'])
                ->orWhere('name', $admin['name'])
                ->first() ?? new User();

            $user->forceFill([
                'name' => $admin['name'],
                'email' => $admin['email'],
                'email_verified_at' => $user->email_verified_at ?? now(),
                'password' => Hash::make($admin['password']),
            ])->save();

            if (! $user->hasRole($adminRole)) {
                $user->assignRole($adminRole);
            }
        }
    }
}
