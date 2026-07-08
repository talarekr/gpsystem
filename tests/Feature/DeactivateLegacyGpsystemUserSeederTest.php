<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DeactivateLegacyGpsystemUserSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_role_seeder_removes_legacy_gpsystem_admin_access_without_touching_current_admins(): void
    {
        Role::findOrCreate(UserRole::OwnerAdmin->value, 'web');

        $legacy = User::query()->create([
            'name' => 'Legacy GPSystem',
            'email' => 'gpsystem@gpsystem.pl',
            'password' => 'password',
            'remember_token' => 'legacy-token',
        ]);
        $legacy->assignRole(UserRole::OwnerAdmin->value);

        DB::table('sessions')->insert([
            'id' => 'legacy-session',
            'user_id' => $legacy->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'payload' => 'payload',
            'last_activity' => time(),
        ]);
        DB::table('password_reset_tokens')->insert([
            'email' => 'gpsystem@gpsystem.pl',
            'token' => 'reset-token',
            'created_at' => now(),
        ]);

        $this->seed(RoleSeeder::class);

        $this->assertDatabaseMissing('users', ['email' => 'gpsystem@gpsystem.pl']);
        $this->assertDatabaseMissing('model_has_roles', ['model_id' => $legacy->id, 'model_type' => User::class]);
        $this->assertDatabaseMissing('sessions', ['user_id' => $legacy->id]);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => 'gpsystem@gpsystem.pl']);

        foreach (['julkadabrowa2002@gmail.com', 'gregor1142@gmail.com', 'talarekr@gmail.com'] as $email) {
            $currentAdmin = User::query()->where('email', $email)->first();

            $this->assertNotNull($currentAdmin);
            $this->assertTrue($currentAdmin->hasRole(UserRole::OwnerAdmin->value));
        }
    }
}
