<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class DeactivateLegacyGpsystemUserSeeder extends Seeder
{
    private const LEGACY_EMAIL = 'gpsystem@gpsystem.pl';

    /**
     * Roles that must not remain attached to the legacy account.
     *
     * @var array<int, string>
     */
    private const BLOCKED_ROLES = [
        UserRole::OwnerAdmin->value,
        'admin',
        'super_admin',
    ];

    public function run(): void
    {
        $user = User::query()->where('email', self::LEGACY_EMAIL)->first();

        if (! $user) {
            $this->info('Legacy user '.self::LEGACY_EMAIL.' not found; no action required.');

            return;
        }

        $rolesBefore = $user->roles()->pluck('name')->all();
        $rolesToRemove = array_values(array_intersect($rolesBefore, self::BLOCKED_ROLES));

        if (in_array(UserRole::OwnerAdmin->value, $rolesToRemove, true) && ! $this->hasReplacementOwnerAdmin((int) $user->getKey())) {
            $this->info('Legacy user '.self::LEGACY_EMAIL.' is the only owner_admin; leaving account unchanged for safety.');

            return;
        }

        $dependencies = $this->dependencySummary((int) $user->getKey());

        DB::transaction(function () use ($user, $rolesToRemove): void {
            foreach ($rolesToRemove as $role) {
                $user->removeRole($role);
            }

            $this->invalidateSessionsAndTokens($user);
            $this->deactivateOrDelete($user);
        });

        $freshUser = User::query()->whereKey($user->getKey())->first();
        $status = $freshUser ? 'deactivated/anonymized' : 'deleted';

        $this->info(sprintf(
            'Legacy user %s found; status=%s; removed_roles=%s; dependencies=%s.',
            self::LEGACY_EMAIL,
            $status,
            $rolesToRemove === [] ? 'none' : implode(',', $rolesToRemove),
            json_encode($dependencies, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ));
    }

    private function deactivateOrDelete(User $user): void
    {
        if (Schema::hasColumn('users', 'deleted_at')) {
            $user->setAttribute('deleted_at', now());
            $user->save();

            return;
        }

        if (Schema::hasColumn('users', 'is_active')) {
            $user->setAttribute('is_active', false);
            $user->save();

            return;
        }

        try {
            $user->delete();

            return;
        } catch (Throwable) {
            $user->exists = true;
        }

        $suffix = $user->getKey() ?: Str::lower(Str::random(8));

        $user->forceFill([
            'email' => 'deleted+'.$suffix.'+'.self::LEGACY_EMAIL,
            'password' => Hash::make(Str::random(64)),
            'remember_token' => null,
        ])->save();
    }

    /**
     * @return array<string, int>
     */
    private function dependencySummary(int $userId): array
    {
        $dependencies = [];

        foreach ([
            'orders' => 'customer_id',
            'parts' => 'created_by',
            'cars_created' => 'created_by_user_id',
            'cars_updated' => 'updated_by_user_id',
            'local_sales' => 'created_by',
        ] as $table => $column) {
            $tableName = str_starts_with($table, 'cars_') ? 'cars' : $table;

            if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, $column)) {
                $dependencies[$table] = DB::table($tableName)->where($column, $userId)->count();
            }
        }

        return $dependencies;
    }

    private function hasReplacementOwnerAdmin(int $legacyUserId): bool
    {
        return User::role(UserRole::OwnerAdmin->value)
            ->whereKeyNot($legacyUserId)
            ->exists();
    }

    private function invalidateSessionsAndTokens(User $user): void
    {
        if (Schema::hasTable('sessions') && Schema::hasColumn('sessions', 'user_id')) {
            DB::table('sessions')->where('user_id', $user->getKey())->delete();
        }

        if (Schema::hasTable('password_reset_tokens')) {
            DB::table('password_reset_tokens')->where('email', self::LEGACY_EMAIL)->delete();
        }

        if (Schema::hasTable('personal_access_tokens') && Schema::hasColumn('personal_access_tokens', 'tokenable_id')) {
            DB::table('personal_access_tokens')
                ->where('tokenable_type', User::class)
                ->where('tokenable_id', $user->getKey())
                ->delete();
        }
    }

    private function info(string $message): void
    {
        $this->command?->info($message);
    }
}
